#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 0 || ( $# -eq 1 && "${1:-}" == "--check" ) ]] || { echo "Usage: bash orderlane-floating-cart.sh [--check]" >&2; exit 2; }
[[ -f artisan && -f public/branding/takeout-order-flow.css ]] || { echo "Run from the Orderlane project with mobile checkout installed." >&2; exit 1; }
patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat >"$patch_file" <<'ORDERLANE_PATCH'
diff --git a/app/Livewire/TakeoutMenu.php b/app/Livewire/TakeoutMenu.php
index fe1932d..03fa119 100644
--- a/app/Livewire/TakeoutMenu.php
+++ b/app/Livewire/TakeoutMenu.php
@@ -124,5 +124,5 @@ class TakeoutMenu extends Component
         if ($this->detailId !== null && $this->add($this->detailId, $this->detailQuantity)) {
             $this->closeItem();
-            $this->reviewOpen = true;
+            $this->dispatch('takeout-item-added');
         }
     }
diff --git a/public/branding/takeout-order-flow.css b/public/branding/takeout-order-flow.css
index 57abb8c..a67311e 100644
--- a/public/branding/takeout-order-flow.css
+++ b/public/branding/takeout-order-flow.css
@@ -80,2 +80,30 @@
     }
 }
+
+.takeout-floating-cart {
+    position: fixed;
+    z-index: 5;
+    left: 50%;
+    bottom: calc(16px + env(safe-area-inset-bottom));
+    transform: translateX(-50%);
+    display: grid;
+    justify-items: center;
+    gap: 6px;
+    width: max-content;
+    max-width: calc(100% - 32px);
+}
+.takeout-floating-cart .btn {
+    min-height: 48px;
+    border-radius: 999px;
+    padding-inline: 18px;
+    box-shadow: 0 4px 14px #3d190b40;
+}
+.takeout-cart-feedback:not(:empty) {
+    padding: 4px 10px;
+    border-radius: 12px;
+    background: #fff2df;
+    color: #48220f;
+}
+.takeout-catalog:has(.takeout-floating-cart) {
+    padding-bottom: calc(100px + env(safe-area-inset-bottom));
+}
diff --git a/resources/views/livewire/takeout-menu.blade.php b/resources/views/livewire/takeout-menu.blade.php
index cb5886f..eb2960a 100644
--- a/resources/views/livewire/takeout-menu.blade.php
+++ b/resources/views/livewire/takeout-menu.blade.php
@@ -71,7 +71,7 @@
         <article class="takeout-photo-card" wire:key="takeout-product-{{ $product->id }}">
             <button type="button" class="takeout-photo" wire:click="showItem({{ $product->id }})"
-                aria-label="View {{ $product->menuText('name', $language) }}">
+                aria-label="View {{ $product->menuText('name', $language) }}{{ isset($cart[$product->id]) ? ', '.$cart[$product->id].' in cart' : '' }}">
                 <img src="{{ $product->image }}" alt="">
-                <span class="btn primary" aria-hidden="true">+</span>
+                <span class="btn primary" aria-hidden="true">{{ $cart[$product->id] ?? '+' }}</span>
             </button>
             <h3>{{ $product->menuText('name', $language) }}</h3>
@@ -85,5 +85,14 @@
 @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
 <footer class="customer-footer"><x-g-icon name="heart"/>Fresh food. Your own pace. Gather.</footer>
-<div class="basket-bar"><button class="btn primary wide" wire:click="$set('reviewOpen',true)" @disabled(! $orderingOpen || ! $cart)>@if(! $orderingOpen) Place order @else<strong>View basket · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong>@endif</button></div>
+@if($cart)
+    <div class="takeout-floating-cart" x-data="{ added: false, timer: null }"
+        x-on:takeout-item-added.window="added = true; clearTimeout(timer); timer = setTimeout(() => added = false, 1800)">
+        <span class="takeout-cart-feedback" role="status" x-text="added ? 'Added to cart' : ''"></span>
+        <button type="button" class="btn primary" wire:click="$set('reviewOpen',true)">
+            <x-g-icon name="shopping-bag"/>
+            View cart · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}
+        </button>
+    </div>
+@endif
 @else
     <header class="takeout-review-header">
ORDERLANE_PATCH
if git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
    echo "Floating cart is already installed."
    exit 0
fi
git apply --check "$patch_file"
if [[ "${1:-}" == "--check" ]]; then
    echo "Compatibility check passed. No files changed."
    exit 0
fi
git apply "$patch_file"
echo "Floating cart installed."
