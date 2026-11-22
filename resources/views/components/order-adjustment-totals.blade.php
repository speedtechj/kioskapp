@props(['order'])
@if($order->complimentary_amount || $order->discount_amount)
    <div class="total-row"><span>Subtotal</span><strong>₱{{ number_format($order->subtotal / 100, 2) }}</strong></div>
    @if($order->complimentary_amount)
        <div class="total-row"><span>Complimentary items</span><strong>−₱{{ number_format($order->complimentary_amount / 100, 2) }}</strong></div>
    @endif
    @if($order->discount_amount)
        <div class="total-row"><span>{{ $order->promotion_discount ? 'Menu promo' : 'Discount' }}</span><strong>−₱{{ number_format($order->discount_amount / 100, 2) }}</strong></div>
    @endif
@endif
