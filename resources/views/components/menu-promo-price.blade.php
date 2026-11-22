@props(['product', 'promotion', 'phase'])

@if($phase === 'active' && $promotion->eligible($product) && ($saving = $promotion->unitSaving($product->price)))
    <span class="menu-promo-price">
        <span class="menu-promo-badge">{{ $promotion->percent }}% OFF</span>
        <del aria-label="Regular price ₱{{ number_format($product->price / 100, 2) }}">₱{{ number_format($product->price / 100, 2) }}</del>
        <strong>₱{{ number_format(($product->price - $saving) / 100, 2) }}</strong>
    </span>
@else
    <strong>₱{{ number_format($product->price / 100, 2) }}</strong>
@endif
