#!/usr/bin/env bash
set -euo pipefail

if [[ $# -gt 1 || (${1:-} != "" && ${1:-} != "--check") ]]; then
    echo "Usage: bash orderlane-large-checkout.sh [--check]" >&2
    exit 2
fi

if [[ ! -f artisan || ! -f resources/views/livewire/takeout-menu.blade.php ]]; then
    echo "Run this from the Orderlane Laravel project folder." >&2
    exit 1
fi

patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat > "$patch_file" <<'ORDERLANE_PATCH'
--- a/resources/views/livewire/takeout-menu.blade.php
+++ b/resources/views/livewire/takeout-menu.blade.php
@@ -2,4 +2,5 @@
 <link rel="stylesheet" href="{{ asset('branding/customer-ordering-hours.css') }}">
+<link rel="stylesheet" href="{{ asset('branding/takeout-checkout.css') }}">
 @endassets
-<main wire:poll.15s class="customer-app takeout-app">
+<main wire:poll.15s class="customer-app takeout-app{{ $reviewOpen ? ' takeout-checkout' : '' }}">
 @if(!$reviewOpen)
@@ -29,12 +30,68 @@
 @else
-<header class="takeout-review-header"><button class="icon-btn" wire:click="$set('reviewOpen',false)" aria-label="Back to takeout menu"><x-g-icon name="chevron-left"/></button><h2>Your takeout order</h2><span></span></header>
-@include('livewire.partials.ordering-closed')
-<section class="pickup-info"><x-g-icon name="map-pin"/><div><strong>Pickup at {{ $kiosk->name }}</strong><p>Prepared fresh. We’ll let you know when it’s ready.</p></div></section>
-<div class="takeout-review-items">@forelse($cartProducts as $product)<article class="cart-item" wire:key="takeout-cart-{{ $product->id }}"><img src="{{ $product->image }}" alt="{{ $product->menuText('name', $language) }}"><div><h3>{{ $product->menuText('name', $language) }}</h3><div class="quantity"><button wire:click="decrease({{ $product->id }})" aria-label="Remove one {{ $product->menuText('name', $language) }}">−</button><span>{{ $cart[$product->id] }} × ₱{{ number_format($product->price/100,2) }}</span><button wire:click="add({{ $product->id }})" aria-label="Add one {{ $product->menuText('name', $language) }}" @disabled(! $orderingOpen)>+</button></div></div><strong>₱{{ number_format($product->price*$cart[$product->id]/100,2) }}</strong></article>@empty<p class="empty">Your basket is empty. Go back to add something delicious.</p>@endforelse</div>
-<label>Name for pickup <span class="muted">(optional)</span><input wire:model="name" maxlength="80" autocomplete="given-name" placeholder="What should we call you?"></label>
-<label>Order notes <span class="muted">(optional)</span><textarea wire:model="notes" maxlength="500" placeholder="e.g. less ice, no onions, etc."></textarea></label>
-<section class="takeout-totals"><div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div><div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div><div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div></section>
-@foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
-<div class="basket-bar takeout-submit"><button class="btn primary wide" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled(! $orderingOpen || ! $cart)><span wire:loading.remove wire:target="submit">Place order · Pay at counter</span><span wire:loading wire:target="submit">Sending your order…</span></button><p>No account required. Split payments are available at the counter.</p></div>
+    <header class="takeout-review-header">
+        <button type="button" class="icon-btn" wire:click="$set('reviewOpen',false)" aria-label="Back to takeout menu">
+            <x-g-icon name="chevron-left"/>
+        </button>
+        <h1>Your takeout order</h1>
+    </header>
+
+    @include('livewire.partials.ordering-closed')
+
+    <div class="takeout-checkout-grid">
+        <div class="takeout-checkout-details">
+            <section class="pickup-info">
+                <x-g-icon name="map-pin"/>
+                <div>
+                    <strong>Pickup at {{ $kiosk->name }}</strong>
+                    <p>We’ll let you know when it’s ready.</p>
+                </div>
+            </section>
+
+            <div class="takeout-review-items">
+                @forelse($cartProducts as $product)
+                    <article class="cart-item" wire:key="takeout-cart-{{ $product->id }}">
+                        <img src="{{ $product->image }}" alt="">
+                        <div class="takeout-item-details">
+                            <h2>{{ $product->menuText('name', $language) }}</h2>
+                            <span class="takeout-unit-price">₱{{ number_format($product->price/100,2) }} each</span>
+                            <div class="quantity">
+                                <button type="button" wire:click="decrease({{ $product->id }})" aria-label="Remove one {{ $product->menuText('name', $language) }}">−</button>
+                                <span>{{ $cart[$product->id] }}</span>
+                                <button type="button" wire:click="add({{ $product->id }})" aria-label="Add one {{ $product->menuText('name', $language) }}" @disabled(! $orderingOpen)>+</button>
+                            </div>
+                        </div>
+                        <strong class="takeout-line-total">₱{{ number_format($product->price*$cart[$product->id]/100,2) }}</strong>
+                    </article>
+                @empty
+                    <p class="empty">Your basket is empty. Go back to add something delicious.</p>
+                @endforelse
+            </div>
+
+            <label>Name for pickup <span class="muted">(optional)</span>
+                <input wire:model="name" maxlength="80" autocomplete="given-name" placeholder="What should we call you?">
+            </label>
+            <label>Order notes <span class="muted">(optional)</span>
+                <textarea wire:model="notes" maxlength="500" placeholder="e.g. less ice, no onions, etc."></textarea>
+            </label>
+        </div>
+
+        <section class="takeout-totals" aria-label="Order totals">
+            <div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
+            <div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div>
+            <div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div>
+        </section>
+    </div>
+
+    @foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)
+        <p class="error" role="alert">{{ $error }}</p>
+    @endforeach
+
+    <footer class="takeout-submit">
+        <button type="button" class="btn primary wide" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled(! $orderingOpen || ! $cart)>
+            <span wire:loading.remove wire:target="submit">Place order · Pay at counter</span>
+            <span wire:loading.inline wire:target="submit" style="display: none">Sending your order…</span>
+        </button>
+        <p>No account required. Split payments are available at the counter.</p>
+    </footer>
 @endif
-</main>
\ No newline at end of file
+</main>
diff --git a/public/branding/takeout-checkout.css b/public/branding/takeout-checkout.css
new file mode 100644
--- /dev/null
+++ b/public/branding/takeout-checkout.css
@@ -0,0 +1,143 @@
+.customer-app.takeout-checkout {
+    width: 100%;
+    max-width: 1200px;
+    padding: clamp(18px, 3vw, 38px);
+    padding-bottom: calc(24px + env(safe-area-inset-bottom));
+}
+
+.takeout-checkout .takeout-review-header {
+    justify-content: flex-start;
+    gap: clamp(10px, 2vw, 24px);
+    padding: 0 0 20px;
+}
+
+.takeout-checkout .takeout-review-header h1 {
+    font-size: clamp(1.55rem, 4vw, 2.65rem);
+    line-height: 1.12;
+    letter-spacing: -.035em;
+}
+
+.takeout-checkout .takeout-review-header .icon-btn {
+    width: 44px;
+    height: 44px;
+}
+
+.takeout-checkout-grid {
+    display: grid;
+    grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
+    gap: clamp(20px, 3vw, 36px);
+}
+
+.takeout-checkout .pickup-info {
+    margin-bottom: 0;
+}
+
+.takeout-checkout .pickup-info strong,
+.takeout-checkout .cart-item h2 {
+    font-size: clamp(1.15rem, 2vw, 1.45rem);
+    overflow-wrap: anywhere;
+}
+
+.takeout-checkout .pickup-info p,
+.takeout-checkout .takeout-unit-price {
+    font-size: 1rem;
+    color: #5c6255;
+}
+
+.takeout-checkout .cart-item {
+    display: grid;
+    grid-template-columns: clamp(64px, 9vw, 106px) minmax(0, 1fr) auto;
+    gap: clamp(10px, 2vw, 20px);
+}
+
+.takeout-checkout .cart-item > img {
+    width: 100%;
+    height: auto;
+    aspect-ratio: 1;
+}
+
+.takeout-checkout .cart-item > strong {
+    font-size: clamp(1.1rem, 2vw, 1.4rem);
+}
+
+.takeout-checkout .quantity {
+    margin-top: 8px;
+    font-size: 1.125rem;
+    font-weight: 650;
+}
+
+.takeout-checkout .quantity button {
+    width: 46px;
+    height: 46px;
+    border-radius: 8px;
+    font-size: 1.4rem;
+}
+
+.takeout-checkout .takeout-checkout-details label {
+    display: block;
+    margin: 20px 0 0;
+    font-size: 1.125rem;
+}
+
+.takeout-checkout .takeout-checkout-details label > :is(input, textarea) {
+    font-size: 1.125rem;
+    min-height: 54px;
+}
+
+.takeout-checkout .takeout-checkout-details textarea {
+    min-height: 88px;
+}
+
+.takeout-checkout .takeout-totals {
+    align-self: start;
+    padding: clamp(16px, 3vw, 28px);
+    margin: 0;
+}
+
+.takeout-checkout .takeout-totals .total-row {
+    font-size: clamp(1.05rem, 2vw, 1.25rem);
+}
+
+.takeout-checkout .takeout-totals .grand-total {
+    font-size: clamp(1.5rem, 3vw, 2rem);
+}
+
+.takeout-checkout .total-row > :last-child {
+    white-space: nowrap;
+}
+
+.takeout-checkout .takeout-submit {
+    margin-top: 24px;
+}
+
+.takeout-checkout .takeout-submit > .btn {
+    min-height: 60px;
+    padding: 12px 16px;
+    font-size: clamp(1.0625rem, 2vw, 1.35rem);
+    line-height: 1.3;
+    white-space: normal;
+}
+
+.takeout-checkout .takeout-submit p {
+    font-size: .875rem;
+    color: #5c6255;
+}
+
+@media (max-width: 760px) {
+    .takeout-checkout-grid {
+        grid-template-columns: minmax(0, 1fr);
+    }
+
+    .takeout-checkout .cart-item {
+        grid-template-columns: clamp(58px, 18vw, 90px) minmax(0, 1fr);
+    }
+
+    .takeout-checkout .cart-item > img {
+        grid-row: span 2;
+        align-self: start;
+    }
+
+    .takeout-checkout .cart-item > .takeout-line-total {
+        grid-column: 2;
+    }
+}
ORDERLANE_PATCH

if git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
    echo "The larger checkout is already installed."
    exit 0
fi

if ! git apply --check "$patch_file"; then
    echo "Your takeout template differs from the tested version. No files changed." >&2
    exit 1
fi

if [[ ${1:-} == "--check" ]]; then
    echo "Checkout compatibility check passed. No files changed."
    exit 0
fi

git apply "$patch_file"
echo "Larger checkout installed. Run php artisan view:clear."
