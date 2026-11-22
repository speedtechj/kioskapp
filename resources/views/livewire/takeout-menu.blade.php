@assets
<link rel="stylesheet" href="{{ asset('branding/customer-ordering-hours.css') }}">
<link rel="stylesheet" href="{{ asset('branding/takeout-checkout.css') }}">
<link rel="stylesheet" href="{{ asset('branding/takeout-order-flow.css') . '?v=local-menu-1' }}">
<link rel="stylesheet" href="{{ asset('branding/ordering-guide.css') . '?v=menu-row-1' }}">
<link rel="stylesheet" href="{{ asset('branding/menu-promotions.css').'?v=2' }}">
<link rel="stylesheet" href="{{ asset('branding/ask-joy.css') }}">
@endassets
@php($askJoyEnabled = \App\Services\GatherAi::customerEnabled($kiosk->business_id))
<main wire:poll.30s x-data="{ menuCategory: null, menuSearch: '' }" class="customer-app takeout-app{{ $reviewOpen || $checkoutOpen || $detailId ? ' takeout-checkout' : ' takeout-catalog' }}{{ $askJoyEnabled ? ' ask-joy-menu' : '' }}">
@if($detailId !== null && !$reviewOpen)
    <header class="takeout-review-header">
        <button type="button" class="icon-btn" wire:click="closeItem" aria-label="Back to menu"><x-g-icon name="chevron-left"/></button>
        <h1>Item details</h1>
    </header>
    @include('livewire.partials.ordering-closed')
    @if($detail)
        <section class="takeout-product-detail">
            <img src="{{ $detail->image }}" alt="">
            <h2>{{ $detail->menuText('name', $language) }}</h2>
            <x-menu-promo-price :product="$detail" :promotion="$promotion" :phase="$promoPhase"/>
            <p>{{ $detail->menuText('description', $language) }}</p>
            <div class="takeout-detail-quantity">
                <strong>Quantity</strong>
                <div class="quantity">
                    <button type="button" wire:click="changeDetailQuantity(false)" aria-label="Decrease quantity" @disabled($detailQuantity <= 1)>−</button>
                    <span aria-live="polite">{{ $detailQuantity }}</span>
                    <button type="button" wire:click="changeDetailQuantity(true)" aria-label="Increase quantity" @disabled(!$orderingOpen || $detailQuantity + ($cart[$detail->id] ?? 0) >= min(99, $detail->pivot->stock))>+</button>
                </div>
            </div>
            <p class="takeout-detail-note">Optional toppings are available in your cart.</p>
            @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
            <footer class="takeout-submit">
                <button type="button" class="btn primary wide" wire:click="addDetail" wire:loading.attr="disabled"
                    @disabled(!$orderingOpen || $detailQuantity + ($cart[$detail->id] ?? 0) > min(99, $detail->pivot->stock))>
                    Add {{ $detailQuantity }} to cart · ₱{{ number_format($detailQuantity * ($detail->price - ($promoPhase === 'active' && $promotion->eligible($detail) ? $promotion->unitSaving($detail->price) : 0)) / 100, 2) }}
                </button>
            </footer>
        </section>
    @else
        <p class="empty">This item is no longer available.</p>
    @endif
@elseif(!$reviewOpen)
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
@include('livewire.partials.customer-menu-heading', ['askJoyEnabled' => $askJoyEnabled, 'takeout' => true])
<div class="phone-menu-controls">
    <x-menu-promo-banner :promotion="$promotion" :items="$promoItems" :phase="$promoPhase" :language="$language"/>
    <div role="group" aria-label="Menu categories" class="categories takeout-categories">
        <button type="button" class="chip selected" aria-pressed="true"
            x-bind:class="{ selected: menuCategory === null }" x-bind:aria-pressed="menuCategory === null"
            x-on:click="menuCategory = null">All</button>
        @foreach($categories as $menuCategory)
            <button type="button" class="chip" data-category="{{ $menuCategory }}" aria-pressed="false"
                x-bind:class="{ selected: menuCategory === $el.dataset.category }"
                x-bind:aria-pressed="menuCategory === $el.dataset.category"
                x-on:click="menuCategory = $el.dataset.category">{{ $menuCategory }}</button>
        @endforeach
    </div>
    <div class="search"><x-g-icon name="magnifying-glass"/><input x-model="menuSearch" aria-label="Search takeout menu" placeholder="Find your next favorite bite"></div>
</div>
<h2 class="phone-menu-category" hidden x-bind:hidden="menuCategory === null" x-text="menuCategory"></h2>
<div class="customer-products takeout-photo-grid">
    @foreach($products as $product)
        <article class="takeout-photo-card" wire:key="takeout-product-{{ $product->id }}"
            data-category="{{ $product->category }}" data-search-name="{{ mb_strtolower($product->menuText('name', $language)) }}"
            x-bind:hidden="(menuCategory !== null && menuCategory !== $el.dataset.category) || !$el.dataset.searchName.includes(menuSearch.toLowerCase())">
            <button type="button" class="takeout-photo" wire:click="showItem({{ $product->id }})"
                aria-label="View {{ $product->menuText('name', $language) }}{{ isset($cart[$product->id]) ? ', '.$cart[$product->id].' in cart' : '' }}">
                <img src="{{ $product->image }}" alt="" decoding="async" @if($loop->index >= 2) loading="lazy" @endif>
                <span class="btn primary" aria-hidden="true">{{ $cart[$product->id] ?? '+' }}</span>
            </button>
            <h3>{{ $product->menuText('name', $language) }}</h3>
            <x-menu-promo-price :product="$product" :promotion="$promotion" :phase="$promoPhase"/>
            @if($product->pivot->stock < 1)<small>Sold out</small>@endif
        </article>
    @endforeach
    <p class="empty takeout-no-matches" role="status">No matching items. Try another category.</p>
</div>
@error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
<footer class="customer-footer"><x-g-icon name="heart"/>Fresh food. Your own pace. Gather.</footer>
@if($cart)
    <div class="takeout-floating-cart" x-data="{ added: false, timer: null }"
        x-on:takeout-item-added.window="added = true; clearTimeout(timer); timer = setTimeout(() => added = false, 1800)">
        <span class="takeout-cart-feedback" role="status" x-text="added ? 'Added to cart' : ''"></span>
        <button type="button" class="btn primary" wire:click="showCheckout">
            <x-g-icon name="shopping-bag"/>
            View cart · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}
        </button>
    </div>
@endif
@else
    <header class="takeout-review-header">
        <button type="button" class="icon-btn" wire:click="backToMenu"
            wire:loading.attr="disabled" wire:target="submit" aria-label="Back to menu">
            <x-g-icon name="chevron-left"/>
        </button>
        <h1>{{ $checkoutOpen ? 'Your takeout order' : 'Your cart' }}</h1>
    </header>

    @include('livewire.partials.ordering-closed')

    <div class="takeout-checkout-grid">
        <div class="takeout-checkout-details">
            @if($checkoutOpen)
            <section class="pickup-info">
                <x-g-icon name="map-pin"/>
                <div>
                    <strong>Pickup at {{ $kiosk->name }}</strong>
                    <p>We’ll let you know when it’s ready.</p>
                </div>
            </section>
            @endif

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

            @include('livewire.partials.topping-picker', ['canIncrease' => $orderingOpen])
            @if($checkoutOpen)
            <label>Name for pickup <span class="muted">(optional)</span>
                <input wire:model="name" maxlength="80" autocomplete="given-name" placeholder="What should we call you?">
            </label>
            <label>Order notes <span class="muted">(optional)</span>
                <textarea wire:model="notes" maxlength="500" placeholder="e.g. less ice, no onions, etc."></textarea>
            </label>
            @endif
        </div>

        <section class="takeout-totals" aria-label="Order totals">
            @if($checkoutOpen)
                @foreach($toppings as $topping)
                    @if($topping->active && ($quantity = $toppingQuantities[$topping->id] ?? 0))
                        <div class="total-row">
                            <span>{{ $topping->name }} × {{ $quantity }}</span>
                            <span>₱{{ number_format($topping->price * $quantity / 100, 2) }}</span>
                        </div>
                    @endif
                @endforeach
            @endif
            <div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
@unless($checkoutOpen)
                @include('livewire.partials.topping-subtotal-breakdown')
            @endunless

            @if($promoSaving > 0)<div class="total-row"><span>Menu promo</span><strong>−₱{{ number_format($promoSaving / 100, 2) }}</strong></div>@endif
<div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div>
            <div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format(($subtotal-$promoSaving+$fee)/100,2) }}</strong></div>
        </section>
    </div>

    @foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)
        <p class="error" role="alert">{{ $error }}</p>
    @endforeach

    <footer class="takeout-submit">
        @if($checkoutOpen)
        <div class="takeout-back-to-menu">
            <button type="button" class="btn wide" wire:click="backToMenu" wire:loading.attr="disabled" wire:target="submit">
                <x-g-icon name="chevron-left"/> Back to menu
            </button>
            <p>Your cart will be saved</p>
        </div>
        <button type="button" class="btn primary wide" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled(! $orderingOpen || ! $cart)>
            <span wire:loading.remove wire:target="submit">Place order · Pay at counter</span>
            <span wire:loading.inline wire:target="submit" style="display: none">Sending your order…</span>
        </button>
        <p>No account required. Split payments are available at the counter.</p>
        @else
            <button type="button" class="btn primary wide" wire:click="showCheckout" @disabled(! $orderingOpen || ! $cart)>Continue to checkout</button>
        @endif
    </footer>
@endif
</main>
