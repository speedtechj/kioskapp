<?php

namespace App\Livewire;

use App\Livewire\Concerns\ApprovesOrderAdjustments;
use App\Livewire\Concerns\ManagesRegisterShift;
use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Services\Access;
use App\Services\CancelOrder;
use App\Services\Money;
use App\Services\OrderAdjustments;
use App\Services\RecordPayment;
use Illuminate\Support\Facades\DB;
use App\Services\RegisterShifts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PaymentDesk extends Component
{
    use \App\Livewire\Concerns\ChecksBusinessAvailability;

    use ApprovesOrderAdjustments;
    use ManagesRegisterShift;
    #[Locked]
    public int $businessId;

    #[Locked]
    public int $kioskId;

    #[Locked]
    public int $orderId;

    #[Locked]
    public string $requestKey;

    public array $payments = [];

    public string $discountType = 'percent';

    public string $discountValue = '';

    private function canAdjustOrder(Order $order): bool
    {
        return in_array($order->status, ['received', 'preparing', 'ready'], true)
            && ($order->payment_status === 'unpaid' || ($order->payment_status === 'paid' && (int) $order->total === 0))
            && $order->payments->isEmpty();
    }

    public function requestDeskDiscount(bool $remove = false): void
    {
        $order = $this->order();
        RegisterShifts::requireOpen($order->kiosk_id);
        if (! $remove) {
            $this->validate(['discountType' => 'required|in:percent,fixed', 'discountValue' => 'required|string|max:10']);
        }
        $action = $remove ? ['kind' => 'remove-discount'] : ['kind' => 'discount', 'type' => $this->discountType, 'value' => $this->discountValue];
        $this->reviewAdjustment($this->orderProposal($order, $action));
    }

    public function requestDeskComplimentary(int $itemId, bool $free): void
    {
        $order = $this->order();
        RegisterShifts::requireOpen($order->kiosk_id);
        $this->reviewAdjustment($this->orderProposal($order, ['kind' => 'complimentary', 'item' => $itemId, 'free' => $free]));
    }

    private function orderProposal(Order $order, array $action): array
    {
        if (! $this->canAdjustOrder($order)) {
            throw ValidationException::withMessages(['adjustment' => 'Adjustments are available before recording payment.']);
        }

        $subtotal = (int) $order->subtotal;
        $discount = (int) $order->discount_amount;
        $complimentary = (int) $order->complimentary_amount;
        $oldNet = $subtotal - $discount - $complimentary;
        if ($action['kind'] === 'complimentary') {
            $item = $order->items->firstWhere('id', $action['item']);
            abort_unless($item, 404);
            if ((bool) $item->complimentary === $action['free'] || ($action['free'] && $discount)) {
                throw ValidationException::withMessages(['adjustment' => 'Remove the discount before making an item free.']);
            }
            $amount = $item->quantity * $item->unit_price;
            $complimentary += $action['free'] ? $amount : -$amount;
            $label = ($action['free'] ? 'Make complimentary: ' : 'Undo complimentary: ').$item->name;
        } elseif ($action['kind'] === 'remove-discount') {
            if (! $discount) {
                throw ValidationException::withMessages(['adjustment' => 'This order has no discount to remove.']);
            }
            $discount = 0;
            $label = 'Remove discount';
        } else {
            if ($discount || $order->items->contains(fn ($item) => $item->complimentary)) {
                throw ValidationException::withMessages(['adjustment' => 'Remove the current adjustment before applying a discount.']);
            }
            $discount = OrderAdjustments::totals($subtotal, $complimentary, $action['type'], $action['value'], 0)['discount'];
            if (! $discount) {
                throw ValidationException::withMessages(['discountValue' => 'Enter a discount greater than zero.']);
            }
            $label = $action['type'] === 'percent' ? $action['value'].'% discount' : '₱'.$action['value'].' discount';
        }

        $basis = collect($order->adjustment_approvals ?? [])->last()['fee_basis'] ?? null;
        if ($basis === null) {
            $rate = (int) $order->kiosk->service_charge_bps;
            $basis = $oldNet > 0 && (int) round($oldNet * $rate / 10000) !== (int) $order->service_charge
                ? [(int) $order->service_charge, $oldNet] : [$rate, 10000];
        }
        $net = $subtotal - $discount - $complimentary;
        if ($net < 0) {
            throw ValidationException::withMessages(['adjustment' => 'Adjustments cannot exceed the order subtotal.']);
        }
        $fee = (int) round($net * $basis[0] / $basis[1]);

        return [
            'action' => $action, 'label' => $label, 'before' => (int) $order->total, 'after' => $net + $fee,
            'old_discount' => (int) $order->discount_amount, 'old_complimentary' => (int) $order->complimentary_amount,
            'discount' => $discount, 'complimentary' => $complimentary, 'fee' => $fee, 'fee_basis' => $basis,
            'revision' => count($order->adjustment_approvals ?? []),
        ];
    }

    private function commitAdjustment(array $proposal, array $approval): void
    {
        $order = $this->order();
        $order = DB::transaction(function () use ($order, $proposal, $approval) {
            \App\Services\BusinessAvailability::enforce($order->business_id, lock: true);
            Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
            RegisterShifts::requireOpen($order->kiosk_id);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail()->load('items', 'payments', 'kiosk');
            if ($proposal !== $this->orderProposal($locked, $proposal['action'])) {
                throw ValidationException::withMessages(['adjustment' => 'The order changed. Review the adjustment again.']);
            }

            $action = $proposal['action'];
            if ($action['kind'] === 'complimentary') {
                $item = $locked->items->firstWhere('id', $action['item']);
                $item->update(['complimentary' => $action['free'], 'total' => $action['free'] ? 0 : $item->quantity * $item->unit_price]);
            }
            $locked->forceFill([
                'discount_amount' => $proposal['discount'], 'complimentary_amount' => $proposal['complimentary'],
                'service_charge' => $proposal['fee'], 'total' => $proposal['after'],
                'payment_status' => $proposal['after'] === 0 ? 'paid' : 'unpaid',
                'adjustment_approvals' => [...($locked->adjustment_approvals ?? []), $approval + [
                    'action' => $proposal['label'], 'before' => $proposal['before'], 'after' => $proposal['after'], 'fee_basis' => $proposal['fee_basis'],
                ]],
            ])->save();

            return $locked;
        });

        $this->payments = [['method' => 'cash', 'amount' => Money::input($order->remainingAmount()), 'reference' => '']];
        $this->requestKey = (string) Str::uuid();
        $this->reset('discountType', 'discountValue');
        session()->flash('payment_success', 'Manager approved. The payment amount has been updated.');
    }

    public function mount(Business $business, Kiosk $kiosk, Order $order): void
    {
        abort_unless($kiosk->business_id === $business->id && $order->business_id === $business->id && $order->kiosk_id === $kiosk->id, 404);
        $this->businessId = $business->id;
        $this->kioskId = $kiosk->id;
        $this->orderId = $order->id;
        $this->requestKey = (string) Str::uuid();
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);
        $this->payments = [['method' => 'cash', 'amount' => Money::input($order->remainingAmount()), 'reference' => '']];
    }

    protected function order(): Order
    {
        Access::authorize($this->businessId, 'orders.pay', $this->kioskId);

        return Order::where('business_id', $this->businessId)->where('kiosk_id', $this->kioskId)->with('payments', 'items', 'table')->findOrFail($this->orderId);
    }

    public function addPayment(): void
    {
        $this->order();
        if (count($this->payments) < 8) {
            $this->payments[] = ['method' => 'gcash', 'amount' => '', 'reference' => ''];
        }
    }

    public function removePayment(int $index): void
    {
        $this->order();
        if (count($this->payments) > 1) {
            unset($this->payments[$index]);
            $this->payments = array_values($this->payments);
        }
    }

    public function record(bool $requireCashReceived = false): void
    {
        $order = app(RecordPayment::class)->handle($this->order(), $requireCashReceived ? RecordPayment::withCashReceived($this->payments) : $this->payments, $this->requestKey);
        $this->requestKey = (string) Str::uuid();
        $this->payments = [['method' => 'cash', 'amount' => Money::input($order->remainingAmount()), 'reference' => '']];
        $this->resetValidation();
        session()->flash('payment_success', 'Payment recorded. The customer can now see the updated balance.');
    }

    public function cancel(): void
    {
        app(CancelOrder::class)->byStaff($this->order());
    }

    public function refund(int $paymentId): void
    {
        $order = $this->order();
        app(CancelOrder::class)->confirmRefund($order, $paymentId);
        $payment = $order->payments()->findOrFail($paymentId);
        session()->flash('payment_success', sprintf('Refund recorded · ₱%s · %s · Order #%04d', number_format($payment->amount / 100, 2), ucfirst($payment->method), $order->id));
    }

    public function render()
    {
        $order = $this->order();
        $business = Business::findOrFail($this->businessId);
        $kiosk = Kiosk::findOrFail($this->kioskId);

        $registerShift = RegisterShifts::active($this->kioskId);
        $canAdjustOrder = $registerShift && $this->canAdjustOrder($order);
        $canApplyDiscount = $canAdjustOrder && (int) $order->discount_amount === 0 && ! $order->items->contains(fn ($item) => $item->complimentary);

        return view('livewire.payment-desk', compact('order', 'business', 'kiosk', 'canApplyDiscount', 'canAdjustOrder', 'registerShift'))->layout('layouts.app', ['title' => 'Split payment']);
    }
}
