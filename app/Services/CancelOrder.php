<?php

namespace App\Services;

use App\Models\Kiosk;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    public function byCustomer(string $slug): void
    {
        $order = Order::where('slug', $slug)->where('source', 'qr')->firstOrFail();
        $this->cancel($order, true);
    }

    public function byStaff(Order $order): void
    {
        Access::authorize($order->business_id, 'orders.pay', $order->kiosk_id);
        $this->cancel($order, false);
    }

    private function cancel(Order $order, bool $customer): void
    {
        DB::transaction(function () use ($order, $customer) {
            BusinessAvailability::enforce($order->business_id, lock: true);
            Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                return;
            }
            $allowed = $customer ? ['received'] : ['received', 'preparing', 'ready'];
            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['cancel' => 'This order has moved on. Please check its status with the cashier.']);
            }
            if ($locked->status === 'received') {
                foreach ($locked->items as $item) {
                    DB::table('kiosk_product')->where('kiosk_id', $locked->kiosk_id)
                        ->where('product_id', $item->product_id)->increment('stock', $item->quantity);
                }
            }
            if ($locked->payment_status === 'paid' && ! $locked->payments()->exists() && $locked->total > 0) {
                $locked->payments()->create([
                    'batch_key' => (string) Str::uuid(), 'sequence' => 0,
                    'amount' => $locked->total, 'method' => in_array($locked->payment_method, ['cash', 'gcash', 'card'], true) ? $locked->payment_method : 'legacy',
                    'confirmed_at' => $locked->updated_at, 'recorded_by' => $locked->user_id,
                ]);
            }
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $customer ? null : auth()->id(),
                'payment_status' => $locked->payments()->exists() ? 'refund_pending' : 'void',
            ])->save();
            if (! $customer) {
                BusinessAudit::record($locked->business_id, 'Order cancelled', BusinessAudit::orderLabel($locked->id), $locked->kiosk_id);
            }
        }, 3);
    }

    public function confirmRefund(Order $order, int $paymentId): void
    {
        Access::authorize($order->business_id, 'orders.pay', $order->kiosk_id);
        DB::transaction(function () use ($order, $paymentId) {
            BusinessAvailability::enforce($order->business_id, lock: true);
            Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'cancelled', 409);
            $payment = $locked->payments()->findOrFail($paymentId);
            if ($payment->refunded_at) {
                return;
            }
            $shift = RegisterShifts::requireOpen($locked->kiosk_id);
            $payment->forceFill(['refunded_at' => now(), 'refunded_by' => auth()->id(), 'refund_shift_id' => $shift->id])->save();
            if (! $locked->payments()->whereNull('refunded_at')->exists()) {
                $locked->update(['payment_status' => 'refunded']);
            }
        }, 3);
    }
}
