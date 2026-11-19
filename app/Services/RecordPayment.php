<?php

namespace App\Services;

use App\Models\Kiosk;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    public static function withCashReceived(array $rows): array
    {
        foreach ($rows as &$row) {
            if (is_array($row) && ($row['method'] ?? null) === 'cash') {
                $row['cash_received'] ??= '';
            }
        }

        return $rows;
    }

    public static function normalize(array $rows): array
    {
        validator(['payments' => $rows], ['payments' => 'required|array|min:1|max:8', 'payments.*.method' => 'required|in:cash,card,gcash', 'payments.*.amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D'], 'payments.*.reference' => 'nullable|string|max:80'])->validate();
        $result = [];
        foreach (array_values($rows) as $row) {
            $amount = Money::cents((string) $row['amount']);
            if ($amount < 1) {
                throw ValidationException::withMessages(['payments' => 'Each payment must be greater than zero.']);
            }
            if ($row['method'] === 'cash' && array_key_exists('cash_received', $row)) {
                $received = $row['cash_received'];
                if (! is_string($received) || $received === '' || Money::cents($received) < $amount) {
                    throw ValidationException::withMessages(['payments' => 'Cash received must cover the cash payment amount.']);
                }
            }
            $result[] = ['method' => $row['method'], 'amount' => $amount, 'reference' => $row['reference'] ?? null];
        }

        return $result;
    }

    public function handle(Order $order, array $rows, string $batchKey): Order
    {
        Access::authorize($order->business_id, 'orders.pay', $order->kiosk_id);
        validator(['batch_key' => $batchKey], ['batch_key' => 'required|uuid'])->validate();
        $payments = self::normalize($rows);

        return DB::transaction(function () use ($order, $payments, $batchKey) {
            BusinessAvailability::enforce($order->business_id, lock: true);
            Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->where('business_id', $order->business_id)->where('kiosk_id', $order->kiosk_id)->lockForUpdate()->firstOrFail();
            $prior = OrderPayment::where('batch_key', $batchKey)->get();
            if ($prior->isNotEmpty()) {
                abort_unless($prior->every(fn ($p) => $p->order_id === $locked->id), 409);

                return $locked->load('payments');
            }
            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['payments' => 'This order was cancelled.']);
            }
            $shift = RegisterShifts::requireOpen($locked->kiosk_id);
            $remaining = $locked->remainingAmount();
            $amount = array_sum(array_column($payments, 'amount'));
            if ($amount > $remaining) {
                throw ValidationException::withMessages(['payments' => 'Payment exceeds the remaining balance of ₱'.number_format($remaining / 100, 2).'. Refresh this order and check the amounts.']);
            }
            foreach ($payments as $i => $payment) {
                $locked->payments()->create($payment + ['batch_key' => $batchKey, 'sequence' => $i, 'recorded_by' => auth()->id(), 'register_shift_id' => $shift->id, 'confirmed_at' => now()]);
            }
            $locked->unsetRelation('payments');
            $paid = (int) $locked->payments()->sum('amount');
            $methods = $locked->payments()->pluck('method')->unique();
            $locked->update(['payment_status' => $paid >= $locked->total ? 'paid' : 'partial', 'payment_method' => $methods->count() > 1 ? 'split' : $methods->first()]);

            return $locked->load('payments');
        });
    }

    public function settleCash(Order $order): Order
    {
        Access::authorize($order->business_id, 'orders.pay', $order->kiosk_id);
        $remaining = $order->remainingAmount();
        if ($remaining === 0) {
            return $order;
        }

        return $this->handle($order, [['method' => 'cash', 'amount' => Money::input($remaining)]], (string) Str::uuid());
    }
}
