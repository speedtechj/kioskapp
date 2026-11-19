@props(['order','staff'=>false])
<section class="card">
    <h2>Order cancelled</h2>
    @forelse($order->payments as $payment)
        <div class="payment-card">
            <div><strong>{{ ucfirst($payment->method) }} · ₱{{ number_format($payment->amount / 100, 2) }}</strong>
            <p>{{ $payment->refunded_at ? 'Refund confirmed' : 'Refund due' }}</p>
            @if($staff && !$payment->refunded_at)
                <button class="btn" wire:click="refund({{ $payment->id }})" wire:loading.attr="disabled"
                    data-gather-title="Confirm {{ $payment->method }} refund?" data-context="₱{{ number_format($payment->amount/100,2) }} · Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }}" data-message="Confirm only after returning this payment through its original method." data-back="Go back" data-commit="Confirm refund">Confirm money returned</button>
            @endif
            </div>
        </div>
    @empty
        <p>No money was collected.</p>
    @endforelse
    @if($order->payment_status==='refund_pending')
        <p>{{ $staff ? 'Return each amount through its original payment method, then confirm it here.' : 'Please contact the cashier to receive your refund.' }}</p>
    @endif
</section>
