@assets
<link rel="stylesheet" href="{{ asset('branding/customer-ordering-hours.css') }}">
<link rel="stylesheet" href="{{ asset('branding/takeout-checkout.css') }}">
@endassets
<main wire:poll.15s class="customer-app takeout-app{{ $reviewOpen ? ' takeout-checkout' : '' }}">
@if(!$reviewOpen)
<header class="takeout-header"><a href="{{ route('takeout',[$kiosk->business,$kiosk]) }}" class="takeout-logo"><img src="{{ $kiosk->logoUrl() }}" width="32" height="32" alt="" style="display:inline-block;object-fit:contain;border-radius:6px;margin-right:6px">{{ $kiosk->business->name==='Gather Restaurant'?'Gather':$kiosk->business->name }}<span class="brand-dot">.</span></a><span class="takeout-location"><x-g-icon name="map-pin"/>{{ $kiosk->name }}</span><span class="takeout-badge">Takeout</span></header>
@if($activeOrder)
    <section class="pickup-info" style="background: var(--soft-orange); flex-wrap: wrap">
        <x-g-icon name="shopping-bag"/>
        <div>
            <strong>Order #{{ str_pad($activeOrder->id, 4, '0', STR_PAD_LEFT) }}</strong>
            <p>{{ ['received' => 'Received', 'preparing' => 'Preparing', 'ready' => 'Ready for pickup'][$activeOrder->status] }}</p>
        </div>
        <a class="btn primary" href="{{ route('tracking', ['token' => $activeOrder->slug]) }}">Track my order</a>
    </section>
@endif
@include('livewire.partials.ordering-closed')
@if($languages)
    <label class="menu-language">Menu language
        <select wire:model.live="menuLanguage">
            <option value="">Restaurant default</option>
            @foreach($languages as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
        </select>
    </label>
@endif

<section class="takeout-welcome"><h1>Good food. To go<span class="brand-dot">.</span></h1><p>Scan. Order. Pick up. No login needed.</p></section>
<section class="takeout-hero"><img src="{{ $products->first()?->image ?? '/images/bowl.jpg' }}" alt="Fresh food for pickup"><div><h2>Freshly made.<br>Ready for<br>pickup.</h2><span></span></div></section>
<h1 class="phone-menu-title">Menu</h1><div class="phone-menu-controls"><div role="group" aria-label="Menu categories" class="categories takeout-categories"><button class="chip {{ $category==='All'?'selected':'' }}" type="button" aria-pressed="{{ $category === 'All' ? 'true' : 'false' }}" wire:click="$set('category','All')">All</button>@foreach($categories as $c)<button class="chip {{ $category===$c?'selected':'' }}" type="button" aria-pressed="{{ $category === $c ? 'true' : 'false' }}" wire:click="$set('category',{{ Js::from($c) }})">{{ $c }}</button>@endforeach</div>
<div class="search"><x-g-icon name="magnifying-glass"/><input wire:model.live.debounce.200ms="search" aria-label="Search takeout menu" placeholder="Find your next favorite bite"></div>
</div>
@if($category !== 'All')
    <h2 class="phone-menu-category">{{ $category }}</h2>
@endif
<div class="customer-products">@forelse($products as $product)<article class="customer-product" wire:key="takeout-product-{{ $product->id }}"><img src="{{ $product->image }}" alt="{{ $product->menuText('name', $language) }}"><div><h3>{{ $product->menuText('name', $language) }}</h3><p>{{ $product->menuText('description', $language) }}</p><div class="customer-product-bottom"><strong>₱{{ number_format($product->price/100,2) }}</strong><button class="btn primary add-small" aria-label="Add {{ $product->menuText('name', $language) }}" wire:click="add({{ $product->id }})" @disabled(! $orderingOpen || $product->pivot->stock<1)>{{ $product->pivot->stock<1?'Sold out':(isset($cart[$product->id])?'Add · '.$cart[$product->id]:'Add') }}</button></div></div></article>@empty<p class="empty">No matching items. Try another category.</p>@endforelse</div>
@error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
<footer class="customer-footer"><x-g-icon name="heart"/>Fresh food. Your own pace. Gather.</footer>
<div class="basket-bar"><button class="btn primary wide" wire:click="$set('reviewOpen',true)" @disabled(! $orderingOpen || ! $cart)>@if(! $orderingOpen) Place order @else<strong>View basket · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong>@endif</button></div>
@else
    <header class="takeout-review-header">
        <button type="button" class="icon-btn" wire:click="$set('reviewOpen',false)" aria-label="Back to takeout menu">
            <x-g-icon name="chevron-left"/>
        </button>
        <h1>Your takeout order</h1>
    </header>

    @include('livewire.partials.ordering-closed')

    <div class="takeout-checkout-grid">
        <div class="takeout-checkout-details">
            <section class="pickup-info">
                <x-g-icon name="map-pin"/>
                <div>
                    <strong>Pickup at {{ $kiosk->name }}</strong>
                    <p>We’ll let you know when it’s ready.</p>
                </div>
            </section>

            <div class="takeout-review-items">
                @forelse($cartProducts as $product)
                    <article class="cart-item" wire:key="takeout-cart-{{ $product->id }}">
                        <img src="{{ $product->image }}" alt="">
                        <div class="takeout-item-details">
                            <h2>{{ $product->menuText('name', $language) }}</h2>
                            <span class="takeout-unit-price">₱{{ number_format($product->price/100,2) }} each</span>
                            <div class="quantity">
                                <button type="button" wire:click="decrease({{ $product->id }})" aria-label="Remove one {{ $product->menuText('name', $language) }}">−</button>
                                <span>{{ $cart[$product->id] }}</span>
                                <button type="button" wire:click="add({{ $product->id }})" aria-label="Add one {{ $product->menuText('name', $language) }}" @disabled(! $orderingOpen)>+</button>
                            </div>
                        </div>
                        <strong class="takeout-line-total">₱{{ number_format($product->price*$cart[$product->id]/100,2) }}</strong>
                    </article>
                @empty
                    <p class="empty">Your basket is empty. Go back to add something delicious.</p>
                @endforelse
            </div>

            <label>Name for pickup <span class="muted">(optional)</span>
                <input wire:model="name" maxlength="80" autocomplete="given-name" placeholder="What should we call you?">
            </label>
            <label>Order notes <span class="muted">(optional)</span>
                <textarea wire:model="notes" maxlength="500" placeholder="e.g. less ice, no onions, etc."></textarea>
            </label>
        </div>

        <section class="takeout-totals" aria-label="Order totals">
            <div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
            <div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div>
            <div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div>
        </section>
    </div>

    @foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)
        <p class="error" role="alert">{{ $error }}</p>
    @endforeach

    <footer class="takeout-submit">
        <button type="button" class="btn primary wide" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled(! $orderingOpen || ! $cart)>
            <span wire:loading.remove wire:target="submit">Place order · Pay at counter</span>
            <span wire:loading.inline wire:target="submit" style="display: none">Sending your order…</span>
        </button>
        <p>No account required. Split payments are available at the counter.</p>
    </footer>
@endif
</main>
