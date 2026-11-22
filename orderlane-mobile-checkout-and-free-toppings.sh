#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 0 || ( $# -eq 1 && "${1:-}" == "--check" ) ]] || { echo "Usage: bash orderlane-mobile-checkout-and-free-toppings.sh [--check]" >&2; exit 2; }
[[ -f artisan && -f resources/views/livewire/partials/topping-subtotal-breakdown.blade.php ]] || { echo "Run from your Orderlane project with optional toppings and the subtotal breakdown installed." >&2; exit 1; }
patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat > "$patch_file" <<'ORDERLANE_PATCH'
diff --git a/app/Livewire/TakeoutMenu.php b/app/Livewire/TakeoutMenu.php
index 492aece..fe1932d 100644
--- a/app/Livewire/TakeoutMenu.php
+++ b/app/Livewire/TakeoutMenu.php
@@ -3,4 +3,6 @@
 namespace App\Livewire;
 
+use App\Livewire\Concerns\ChecksBusinessAvailability;
+use App\Livewire\Concerns\SelectsToppings;
 use App\Models\Business;
 use App\Models\Kiosk;
@@ -15,6 +17,6 @@ use Livewire\Component;
 class TakeoutMenu extends Component
 {
-    use \App\Livewire\Concerns\SelectsToppings;
-    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+    use ChecksBusinessAvailability;
+    use SelectsToppings;
 
     #[Locked]
@@ -32,4 +34,13 @@ class TakeoutMenu extends Component
     public bool $reviewOpen = false;
 
+    #[Locked]
+    public ?int $detailId = null;
+
+    #[Locked]
+    public int $detailQuantity = 1;
+
+    #[Locked]
+    public bool $checkoutOpen = false;
+
     public string $name = '';
 
@@ -86,17 +97,69 @@ class TakeoutMenu extends Component
     }
 
-    public function add(int $id): void
+    public function showItem(int $id): void
+    {
+        $kiosk = $this->kiosk();
+        $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->findOrFail($id);
+        $this->detailId = $id;
+        $this->detailQuantity = 1;
+        $this->resetValidation('cart');
+        $this->scrollToTop();
+    }
+
+    public function closeItem(): void
+    {
+        $this->detailId = null;
+        $this->scrollToTop();
+    }
+
+    public function changeDetailQuantity(bool $increase): void
+    {
+        if ($this->detailId !== null) {
+            $this->detailQuantity = max(1, min(99, $this->detailQuantity + ($increase ? 1 : -1)));
+        }
+    }
+
+    public function addDetail(): void
+    {
+        if ($this->detailId !== null && $this->add($this->detailId, $this->detailQuantity)) {
+            $this->closeItem();
+            $this->reviewOpen = true;
+        }
+    }
+
+    public function showCheckout(): void
+    {
+        if ($this->cart && RegisterShifts::active($this->kiosk()->id)) {
+            $this->reviewOpen = true;
+            $this->checkoutOpen = true;
+            $this->scrollToTop();
+        }
+    }
+
+    public function closeCheckout(): void
+    {
+        $this->checkoutOpen = false;
+        $this->scrollToTop();
+    }
+
+    public function add(int $id, int $count = 1): bool
     {
         $kiosk = $this->kiosk();
         if (! RegisterShifts::active($kiosk->id)) {
-            return;
+            return false;
         }
+        abort_unless($count >= 1 && $count <= 99, 422);
         $product = $kiosk->visibleProducts()->where('business_id', $kiosk->business_id)->where('active', true)->findOrFail($id);
-        $quantity = ($this->cart[$id] ?? 0) + 1;
+        $quantity = ($this->cart[$id] ?? 0) + $count;
         if ($quantity <= $product->pivot->stock && $quantity <= 99) {
             $this->cart[$id] = $quantity;
-        } else {
-            $this->addError('cart', 'This item has reached its available quantity.');
+            $this->resetValidation('cart');
+
+            return true;
         }
+
+        $this->addError('cart', 'This item has reached its available quantity.');
+
+        return false;
     }
 
@@ -124,9 +187,15 @@ class TakeoutMenu extends Component
 
     public function updatedReviewOpen(): void
+    {
+        $this->checkoutOpen = false;
+        $this->scrollToTop();
+    }
+
+    private function scrollToTop(): void
     {
         $this->js('window.scrollTo({top: 0, behavior: "instant"})');
     }
 
-    public string $menuLanguage = "";
+    public string $menuLanguage = '';
 
     public function render()
@@ -148,5 +217,7 @@ class TakeoutMenu extends Component
         $activeOrder = $this->reviewOpen ? null : $this->activeTakeoutOrder($kiosk);
 
-        return view('livewire.takeout-menu', compact('toppings', 'toppingSubtotal', 'activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
+        $detail = $all->firstWhere('id', $this->detailId);
+
+        return view('livewire.takeout-menu', compact('detail', 'toppings', 'toppingSubtotal', 'activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
     }
 }
diff --git a/app/Livewire/Terminal.php b/app/Livewire/Terminal.php
index db7da93..a863f67 100644
--- a/app/Livewire/Terminal.php
+++ b/app/Livewire/Terminal.php
@@ -4,5 +4,7 @@ namespace App\Livewire;
 
 use App\Livewire\Concerns\ApprovesOrderAdjustments;
+use App\Livewire\Concerns\ChecksBusinessAvailability;
 use App\Livewire\Concerns\ManagesRegisterShift;
+use App\Livewire\Concerns\SelectsToppings;
 use App\Models\Business;
 use App\Models\Kiosk;
@@ -10,4 +12,5 @@ use App\Models\Order;
 use App\Services\Access;
 use App\Services\OrderAdjustments;
+use App\Services\OrderToppings;
 use App\Services\PlaceOrder;
 use App\Services\RecordPayment;
@@ -20,9 +23,9 @@ use Livewire\Component;
 class Terminal extends Component
 {
-    use \App\Livewire\Concerns\SelectsToppings;
-    use \App\Livewire\Concerns\ChecksBusinessAvailability;
-
     use ApprovesOrderAdjustments;
+    use ChecksBusinessAvailability;
     use ManagesRegisterShift;
+    use SelectsToppings;
+
     #[Locked]
     public int $businessId;
@@ -47,4 +50,7 @@ class Terminal extends Component
     public array $complimentaryIds = [];
 
+    #[Locked]
+    public array $complimentaryToppingIds = [];
+
     #[Locked]
     public string $discountType = 'percent';
@@ -123,5 +129,5 @@ class Terminal extends Component
     {
         $this->authorizeAdjustment();
-        if ($this->complimentaryIds || ($remove !== ($this->discountValue !== ''))) {
+        if ($this->complimentaryToppingIds || $this->complimentaryIds || ($remove !== ($this->discountValue !== ''))) {
             throw ValidationException::withMessages(['adjustment' => 'Remove the current adjustment before switching types.']);
         }
@@ -148,4 +154,20 @@ class Terminal extends Component
     }
 
+    public function requestCartComplimentaryTopping(int $id): void
+    {
+        $this->authorizeAdjustment();
+        abort_unless(isset($this->toppingQuantities[$id]), 422);
+        if ($this->discountValue !== '') {
+            throw ValidationException::withMessages(['adjustment' => 'Remove the discount before making a topping free.']);
+        }
+
+        $topping = OrderToppings::catalog($this->businessId)->where('active', true)->find($id);
+        abort_unless($topping, 404);
+        $free = ! in_array($id, $this->complimentaryToppingIds, true);
+        $ids = $free ? [...$this->complimentaryToppingIds, $id] : array_values(array_diff($this->complimentaryToppingIds, [$id]));
+        $label = ($free ? 'Make topping complimentary: ' : 'Undo complimentary topping: ').$topping->name;
+        $this->reviewAdjustment($this->cartProposal('percent', '', $this->complimentaryIds, $label, $ids));
+    }
+
     private function authorizeAdjustment(): void
     {
@@ -156,10 +178,10 @@ class Terminal extends Component
     private function ensureCartEditable(): void
     {
-        if ($this->discountValue !== '' || $this->complimentaryIds) {
+        if ($this->discountValue !== '' || $this->complimentaryIds || $this->complimentaryToppingIds) {
             throw ValidationException::withMessages(['cart' => 'Remove the approved adjustment before changing items or quantities.']);
         }
     }
 
-    private function cartProposal(string $type, string $value, array $ids, string $label): array
+    private function cartProposal(string $type, string $value, array $ids, string $label, ?array $toppingIds = null): array
     {
         $kiosk = Kiosk::findOrFail($this->kioskId);
@@ -169,8 +191,10 @@ class Terminal extends Component
         }
         $lineTotal = fn ($product) => $product->price * (int) $this->cart[$product->id];
-        $toppingLines = \App\Services\OrderToppings::lines($this->businessId, $this->toppingQuantities);
+        $toppingLines = OrderToppings::lines($this->businessId, $this->toppingQuantities);
+        $toppingIds ??= $this->complimentaryToppingIds;
+        $toppingComp = fn (array $ids) => collect($toppingLines)->whereIn('id', $ids)->sum('total');
         $subtotal = $products->sum($lineTotal) + array_sum(array_column($toppingLines, 'total'));
-        $before = OrderAdjustments::totals($subtotal, $products->whereIn('id', $this->complimentaryIds)->sum($lineTotal), $this->discountType, $this->discountValue, $kiosk->service_charge_bps)['total'];
-        $after = OrderAdjustments::totals($subtotal, $products->whereIn('id', $ids)->sum($lineTotal), $type, $value, $kiosk->service_charge_bps);
+        $before = OrderAdjustments::totals($subtotal, $products->whereIn('id', $this->complimentaryIds)->sum($lineTotal) + $toppingComp($this->complimentaryToppingIds), $this->discountType, $this->discountValue, $kiosk->service_charge_bps)['total'];
+        $after = OrderAdjustments::totals($subtotal, $products->whereIn('id', $ids)->sum($lineTotal) + $toppingComp($toppingIds), $type, $value, $kiosk->service_charge_bps);
         if ($value !== '' && $after['discount'] === 0) {
             throw ValidationException::withMessages(['discountValue' => 'Enter a discount greater than zero.']);
@@ -181,6 +205,6 @@ class Terminal extends Component
         return [
             'label' => $label, 'before' => $before, 'after' => $after['total'],
-            'type' => $type, 'value' => $value, 'ids' => $ids,
-            'fingerprint' => PlaceOrder::adjustmentFingerprint($this->cart, $prices, $kiosk->service_charge_bps, $toppingLines),
+            'type' => $type, 'value' => $value, 'ids' => $ids, 'toppingIds' => $toppingIds,
+            'fingerprint' => PlaceOrder::adjustmentFingerprint($this->cart, $prices, $kiosk->service_charge_bps, PlaceOrder::toppingsForApproval($toppingLines, $toppingIds)),
         ];
     }
@@ -189,5 +213,5 @@ class Terminal extends Component
     {
         $this->authorizeAdjustment();
-        if ($proposal !== $this->cartProposal($proposal['type'], $proposal['value'], $proposal['ids'], $proposal['label'])) {
+        if ($proposal !== $this->cartProposal($proposal['type'], $proposal['value'], $proposal['ids'], $proposal['label'], $proposal['toppingIds'] ?? [])) {
             throw ValidationException::withMessages(['adjustment' => 'The order changed. Review the adjustment again.']);
         }
@@ -196,4 +220,5 @@ class Terminal extends Component
         $this->discountValue = $proposal['value'];
         $this->complimentaryIds = $proposal['ids'];
+        $this->complimentaryToppingIds = $proposal['toppingIds'];
         $this->approvedCart = $proposal['fingerprint'];
         $this->adjustmentApprovals[] = $approval + ['action' => $proposal['label'], 'before' => $proposal['before'], 'after' => $proposal['after']];
@@ -213,5 +238,5 @@ class Terminal extends Component
         }
         $cashReceived = $method === 'cash' && ($requireCashReceived || $this->cashReceived !== '') ? $this->cashReceived : null;
-        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, $table, $method, payments: $rows, cashReceived: $cashReceived, complimentaryIds: $this->complimentaryIds, discountType: $this->discountType, discountValue: $this->discountValue, adjustmentApprovals: $this->adjustmentApprovals, approvedCart: $this->approvedCart, toppings: $this->toppingQuantities);
+        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, $table, $method, payments: $rows, cashReceived: $cashReceived, complimentaryIds: $this->complimentaryIds, discountType: $this->discountType, discountValue: $this->discountValue, adjustmentApprovals: $this->adjustmentApprovals, approvedCart: $this->approvedCart, toppings: $this->toppingQuantities, complimentaryToppingIds: $this->complimentaryToppingIds);
         $this->finishCheckout($order);
     }
@@ -235,4 +260,5 @@ class Terminal extends Component
             approvedCart: $this->approvedCart,
             toppings: $this->toppingQuantities,
+            complimentaryToppingIds: $this->complimentaryToppingIds,
         );
         $this->finishCheckout($order);
@@ -246,5 +272,5 @@ class Terminal extends Component
         $this->cart = [];
         $this->toppingQuantities = [];
-        $this->reset('cashReceived', 'complimentaryIds', 'discountType', 'discountValue', 'proposedDiscountType', 'proposedDiscountValue', 'adjustmentApprovals', 'approvedCart');
+        $this->reset('cashReceived', 'complimentaryIds', 'complimentaryToppingIds', 'discountType', 'discountValue', 'proposedDiscountType', 'proposedDiscountValue', 'adjustmentApprovals', 'approvedCart');
         $this->resetValidation();
         $this->paymentRows = [['method' => 'gcash', 'amount' => ''], ['method' => 'cash', 'amount' => '']];
@@ -291,4 +317,5 @@ class Terminal extends Component
         $complimentary = $cartProducts->whereIn('id', $this->complimentaryIds)
             ->sum(fn ($product) => $product->price * ($this->cart[$product->id] ?? 0));
+        $complimentary += $this->toppingSubtotal($toppings->whereIn('id', $this->complimentaryToppingIds));
         $adjustmentsValid = true;
         try {
diff --git a/app/Services/PlaceOrder.php b/app/Services/PlaceOrder.php
index 8b82e3a..345ed53 100644
--- a/app/Services/PlaceOrder.php
+++ b/app/Services/PlaceOrder.php
@@ -22,5 +22,12 @@ class PlaceOrder
     }
 
-    public function handle(Kiosk $kiosk, array $cart, string $requestKey, ?DiningTable $table = null, ?string $method = null, ?string $name = null, ?string $notes = null, ?array $payments = null, bool $payAtPickup = false, ?string $cashReceived = null, array $complimentaryIds = [], string $discountType = 'percent', string $discountValue = '', array $adjustmentApprovals = [], string $approvedCart = '', array $toppings = []): Order
+    public static function toppingsForApproval(array $lines, array $complimentaryIds): array
+    {
+        return array_map(fn (array $line) => in_array((int) $line['id'], $complimentaryIds, true)
+            ? $line + ['complimentary' => true]
+            : $line, $lines);
+    }
+
+    public function handle(Kiosk $kiosk, array $cart, string $requestKey, ?DiningTable $table = null, ?string $method = null, ?string $name = null, ?string $notes = null, ?array $payments = null, bool $payAtPickup = false, ?string $cashReceived = null, array $complimentaryIds = [], string $discountType = 'percent', string $discountValue = '', array $adjustmentApprovals = [], string $approvedCart = '', array $toppings = [], array $complimentaryToppingIds = []): Order
     {
         abort_unless($kiosk->active, 404);
@@ -30,5 +37,5 @@ class PlaceOrder
         abort_if($payAtPickup && ($table !== null || $method !== null || $payments !== null), 422);
         $isPos = $payAtPickup || $method !== null || $payments !== null;
-        abort_if(! $isPos && ($complimentaryIds !== [] || $discountValue !== ''), 403);
+        abort_if(! $isPos && ($complimentaryIds !== [] || $complimentaryToppingIds !== [] || $discountValue !== ''), 403);
         validator(['complimentary' => $complimentaryIds], ['complimentary' => 'array|max:50', 'complimentary.*' => 'integer|distinct|min:1'])->validate();
         $complimentaryIds = array_map('intval', $complimentaryIds);
@@ -49,5 +56,5 @@ class PlaceOrder
         }
 
-        return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart, $toppings) {
+        return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart, $toppings, $complimentaryToppingIds) {
             BusinessAvailability::enforce($kiosk->business_id, lock: true);
             $locked = Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
@@ -69,9 +76,15 @@ class PlaceOrder
             }
             $toppingLines = OrderToppings::lines($kiosk->business_id, $toppings, lock: true);
-            if ($complimentaryIds && $discountValue !== '') {
+            validator(['ids' => $complimentaryToppingIds], ['ids' => 'array|max:50', 'ids.*' => 'integer|distinct|min:1'])->validate();
+            $complimentaryToppingIds = array_map('intval', $complimentaryToppingIds);
+            if (array_diff($complimentaryToppingIds, array_column($toppingLines, 'id'))) {
+                throw ValidationException::withMessages(['adjustment' => 'Complimentary toppings must be in the cart.']);
+            }
+            $approvedToppings = self::toppingsForApproval($toppingLines, $complimentaryToppingIds);
+            if (($complimentaryIds || $complimentaryToppingIds) && $discountValue !== '') {
                 throw ValidationException::withMessages(['adjustment' => 'Choose either a discount or complimentary items.']);
             }
-            if (($complimentaryIds || $discountValue !== '') && (! $adjustmentApprovals
-                || $approvedCart !== self::adjustmentFingerprint($cart, $products->pluck('price', 'id')->all(), $locked->service_charge_bps, $toppingLines))) {
+            if (($complimentaryIds || $complimentaryToppingIds || $discountValue !== '') && (! $adjustmentApprovals
+                || $approvedCart !== self::adjustmentFingerprint($cart, $products->pluck('price', 'id')->all(), $locked->service_charge_bps, $approvedToppings))) {
                 throw ValidationException::withMessages(['adjustment' => 'Get manager approval for the current cart before checkout.']);
             }
@@ -80,4 +93,11 @@ class PlaceOrder
             $items = [];
             $complimentary = 0;
+            foreach ($approvedToppings as &$line) {
+                if ($line['complimentary'] ?? false) {
+                    $complimentary += $line['total'];
+                    $line['total'] = 0;
+                }
+            }
+            unset($line);
             foreach ($products as $product) {
                 $qty = (int) $cart[$product->id];
@@ -99,5 +119,5 @@ class PlaceOrder
             }
             if ($toppingLines) {
-                $order->forceFill(['topping_lines' => $toppingLines])->save();
+                $order->forceFill(['topping_lines' => $approvedToppings])->save();
             }
             $order->items()->createMany($items);
diff --git a/public/branding/takeout-order-flow.css b/public/branding/takeout-order-flow.css
new file mode 100644
index 0000000..5565d7e
--- /dev/null
+++ b/public/branding/takeout-order-flow.css
@@ -0,0 +1,69 @@
+.takeout-catalog .takeout-photo-grid {
+    display: grid;
+    grid-template-columns: repeat(2, minmax(0, 1fr));
+    gap: 16px 10px;
+}
+.takeout-photo-card {
+    min-width: 0;
+    overflow: hidden;
+    border: 1px solid var(--line);
+    border-radius: 14px;
+    background: #fff7ea;
+}
+.takeout-photo { position: relative; }
+.takeout-photo img {
+    display: block;
+    width: 100%;
+    aspect-ratio: 1;
+    object-fit: cover;
+}
+.takeout-photo .btn {
+    position: absolute;
+    right: 8px;
+    bottom: 8px;
+    width: 44px;
+    min-height: 44px;
+    padding: 0;
+    border-radius: 50%;
+    justify-content: center;
+    font-size: 1.5rem;
+}
+.takeout-photo-card h3,
+.takeout-photo-card > strong,
+.takeout-photo-card > small {
+    display: block;
+    margin: 6px 10px;
+    overflow-wrap: anywhere;
+}
+.takeout-product-detail { max-width: 570px; margin-inline: auto; }
+.takeout-product-detail > img {
+    width: 100%;
+    aspect-ratio: 4 / 3;
+    object-fit: cover;
+    border-radius: 16px;
+}
+.takeout-product-detail h2 { margin: 12px 0 4px; font-size: clamp(1.6rem, 4vw, 2rem); }
+.takeout-price { font-size: 1.35rem; color: #a84108; }
+.takeout-product-detail > p { margin-block: 12px; }
+.takeout-detail-quantity {
+    display: flex;
+    justify-content: space-between;
+    align-items: center;
+    gap: 10px;
+    background: #fff2df;
+    border-radius: 12px;
+    padding: 8px 12px;
+}
+.takeout-detail-quantity .quantity button { width: 44px; height: 44px; }
+.takeout-detail-note { color: #5b534a; }
+@media (max-width: 760px) {
+    .takeout-catalog .takeout-hero,
+    .takeout-catalog .takeout-welcome { display: none; }
+    .takeout-checkout .takeout-submit {
+        position: sticky;
+        bottom: 0;
+        z-index: 2;
+        padding-block: 8px max(8px, env(safe-area-inset-bottom));
+        background: var(--paper);
+    }
+}
diff --git a/resources/views/livewire/partials/saved-topping-lines.blade.php b/resources/views/livewire/partials/saved-topping-lines.blade.php
index e625011..cc0916c 100644
--- a/resources/views/livewire/partials/saved-topping-lines.blade.php
+++ b/resources/views/livewire/partials/saved-topping-lines.blade.php
@@ -1,5 +1,5 @@
 @foreach($order->topping_lines ?? [] as $topping)
     <div class="{{ ($kitchen ?? false) ? 'ticket-item' : 'total-row' }}">
-        <span>{{ $topping['quantity'] }} × {{ $topping['name'] }} (extra)</span>
+        <span>{{ $topping['quantity'] }} × {{ $topping['name'] }} (extra) @if($topping['complimentary'] ?? false) · Complimentary @endif</span>
         @unless($kitchen ?? false)<strong>₱{{ number_format($topping['total'] / 100, 2) }}</strong>@endunless
     </div>
diff --git a/resources/views/livewire/partials/topping-subtotal-breakdown.blade.php b/resources/views/livewire/partials/topping-subtotal-breakdown.blade.php
index f76fd47..69c1e4c 100644
--- a/resources/views/livewire/partials/topping-subtotal-breakdown.blade.php
+++ b/resources/views/livewire/partials/topping-subtotal-breakdown.blade.php
@@ -6,5 +6,12 @@
                 <div class="total-row">
                     <span>Includes extra {{ $topping->name }} ({{ $quantity }} {{ \Illuminate\Support\Str::plural('piece', $quantity) }})</span>
-                    <span>₱{{ number_format($topping->price * $quantity / 100, 2) }}</span>
+                    <span>
+                        @if(in_array($topping->id, $freeToppingIds ?? [], true))
+                            <del>₱{{ number_format($topping->price * $quantity / 100, 2) }}</del>
+                            <small>Complimentary</small>
+                        @else
+                            ₱{{ number_format($topping->price * $quantity / 100, 2) }}
+                        @endif
+                    </span>
                 </div>
             @endif
diff --git a/resources/views/livewire/takeout-menu.blade.php b/resources/views/livewire/takeout-menu.blade.php
index 1547095..4f7d351 100644
--- a/resources/views/livewire/takeout-menu.blade.php
+++ b/resources/views/livewire/takeout-menu.blade.php
@@ -2,7 +2,40 @@
 <link rel="stylesheet" href="{{ asset('branding/customer-ordering-hours.css') }}">
 <link rel="stylesheet" href="{{ asset('branding/takeout-checkout.css') }}">
+<link rel="stylesheet" href="{{ asset('branding/takeout-order-flow.css') }}">
 @endassets
-<main wire:poll.15s class="customer-app takeout-app{{ $reviewOpen ? ' takeout-checkout' : '' }}">
-@if(!$reviewOpen)
+<main wire:poll.15s class="customer-app takeout-app{{ $reviewOpen || $checkoutOpen || $detailId ? ' takeout-checkout' : ' takeout-catalog' }}">
+@if($detailId !== null && !$reviewOpen)
+    <header class="takeout-review-header">
+        <button type="button" class="icon-btn" wire:click="closeItem" aria-label="Back to menu"><x-g-icon name="chevron-left"/></button>
+        <h1>Item details</h1>
+    </header>
+    @include('livewire.partials.ordering-closed')
+    @if($detail)
+        <section class="takeout-product-detail">
+            <img src="{{ $detail->image }}" alt="">
+            <h2>{{ $detail->menuText('name', $language) }}</h2>
+            <strong class="takeout-price">₱{{ number_format($detail->price / 100, 2) }}</strong>
+            <p>{{ $detail->menuText('description', $language) }}</p>
+            <div class="takeout-detail-quantity">
+                <strong>Quantity</strong>
+                <div class="quantity">
+                    <button type="button" wire:click="changeDetailQuantity(false)" aria-label="Decrease quantity" @disabled($detailQuantity <= 1)>−</button>
+                    <span aria-live="polite">{{ $detailQuantity }}</span>
+                    <button type="button" wire:click="changeDetailQuantity(true)" aria-label="Increase quantity" @disabled(!$orderingOpen || $detailQuantity + ($cart[$detail->id] ?? 0) >= min(99, $detail->pivot->stock))>+</button>
+                </div>
+            </div>
+            <p class="takeout-detail-note">Optional toppings are available in your cart.</p>
+            @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
+            <footer class="takeout-submit">
+                <button type="button" class="btn primary wide" wire:click="addDetail" wire:loading.attr="disabled"
+                    @disabled(!$orderingOpen || $detailQuantity + ($cart[$detail->id] ?? 0) > min(99, $detail->pivot->stock))>
+                    Add {{ $detailQuantity }} to cart · ₱{{ number_format($detailQuantity * $detail->price / 100, 2) }}
+                </button>
+            </footer>
+        </section>
+    @else
+        <p class="empty">This item is no longer available.</p>
+    @endif
+@elseif(!$reviewOpen)
 <header class="takeout-header"><a href="{{ route('takeout',[$kiosk->business,$kiosk]) }}" class="takeout-logo"><img src="{{ $kiosk->logoUrl() }}" width="32" height="32" alt="" style="display:inline-block;object-fit:contain;border-radius:6px;margin-right:6px">{{ $kiosk->business->name==='Gather Restaurant'?'Gather':$kiosk->business->name }}<span class="brand-dot">.</span></a><span class="takeout-location"><x-g-icon name="map-pin"/>{{ $kiosk->name }}</span><span class="takeout-badge">Takeout</span></header>
 @if($activeOrder)
@@ -34,5 +67,20 @@
     <h2 class="phone-menu-category">{{ $category }}</h2>
 @endif
-<div class="customer-products">@forelse($products as $product)<article class="customer-product" wire:key="takeout-product-{{ $product->id }}"><img src="{{ $product->image }}" alt="{{ $product->menuText('name', $language) }}"><div><h3>{{ $product->menuText('name', $language) }}</h3><p>{{ $product->menuText('description', $language) }}</p><div class="customer-product-bottom"><strong>₱{{ number_format($product->price/100,2) }}</strong><button class="btn primary add-small" aria-label="Add {{ $product->menuText('name', $language) }}" wire:click="add({{ $product->id }})" @disabled(! $orderingOpen || $product->pivot->stock<1)>{{ $product->pivot->stock<1?'Sold out':(isset($cart[$product->id])?'Add · '.$cart[$product->id]:'Add') }}</button></div></div></article>@empty<p class="empty">No matching items. Try another category.</p>@endforelse</div>
+<div class="customer-products takeout-photo-grid">
+    @forelse($products as $product)
+        <article class="takeout-photo-card" wire:key="takeout-product-{{ $product->id }}">
+            <div class="takeout-photo">
+                <img src="{{ $product->image }}" alt="">
+                <button type="button" class="btn primary" wire:click="showItem({{ $product->id }})"
+                    aria-label="View {{ $product->menuText('name', $language) }}">+</button>
+            </div>
+            <h3>{{ $product->menuText('name', $language) }}</h3>
+            <strong>₱{{ number_format($product->price / 100, 2) }}</strong>
+            @if($product->pivot->stock < 1)<small>Sold out</small>@endif
+        </article>
+    @empty
+        <p class="empty">No matching items. Try another category.</p>
+    @endforelse
+</div>
 @error('cart')<p class="error" role="alert">{{ $message }}</p>@enderror
 <footer class="customer-footer"><x-g-icon name="heart"/>Fresh food. Your own pace. Gather.</footer>
@@ -40,8 +88,9 @@
 @else
     <header class="takeout-review-header">
-        <button type="button" class="icon-btn" wire:click="$set('reviewOpen',false)" aria-label="Back to takeout menu">
+        <button type="button" class="icon-btn" @if($checkoutOpen) wire:click="closeCheckout" aria-label="Back to cart"
+            @else wire:click="$set('reviewOpen',false)" aria-label="Back to takeout menu" @endif>
             <x-g-icon name="chevron-left"/>
         </button>
-        <h1>Your takeout order</h1>
+        <h1>{{ $checkoutOpen ? 'Checkout' : 'Your cart' }}</h1>
     </header>
 
@@ -50,4 +99,5 @@
     <div class="takeout-checkout-grid">
         <div class="takeout-checkout-details">
+            @if($checkoutOpen)
             <section class="pickup-info">
                 <x-g-icon name="map-pin"/>
@@ -57,5 +107,7 @@
                 </div>
             </section>
+            @endif
 
+            @unless($checkoutOpen)
             <div class="takeout-review-items">
                 @forelse($cartProducts as $product)
@@ -79,4 +131,5 @@
 
             @include('livewire.partials.topping-picker', ['canIncrease' => $orderingOpen])
+            @else
 
             <label>Name for pickup <span class="muted">(optional)</span>
@@ -86,12 +139,31 @@
                 <textarea wire:model="notes" maxlength="500" placeholder="e.g. less ice, no onions, etc."></textarea>
             </label>
+            @endunless
         </div>
 
         <section class="takeout-totals" aria-label="Order totals">
+            @if($checkoutOpen)
+                @foreach($cartProducts as $product)
+                    <div class="total-row">
+                        <span>{{ $product->menuText('name', $language) }} × {{ $cart[$product->id] }}</span>
+                        <span>₱{{ number_format($product->price * $cart[$product->id] / 100, 2) }}</span>
+                    </div>
+                @endforeach
+                @foreach($toppings as $topping)
+                    @if($topping->active && ($quantity = $toppingQuantities[$topping->id] ?? 0))
+                        <div class="total-row">
+                            <span>{{ $topping->name }} × {{ $quantity }}</span>
+                            <span>₱{{ number_format($topping->price * $quantity / 100, 2) }}</span>
+                        </div>
+                    @endif
+                @endforeach
+            @endif
             <div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
-@include('livewire.partials.topping-subtotal-breakdown')
+@unless($checkoutOpen)
+                @include('livewire.partials.topping-subtotal-breakdown')
+            @endunless
 
             <div class="total-row"><span>Service charge ({{ $kiosk->service_charge_bps/100 }}%)</span><span>₱{{ number_format($fee/100,2) }}</span></div>
-            <div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div>
+            <div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div>
         </section>
     </div>
@@ -102,4 +174,5 @@
 
     <footer class="takeout-submit">
+        @if($checkoutOpen)
         <button type="button" class="btn primary wide" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" @disabled(! $orderingOpen || ! $cart)>
             <span wire:loading.remove wire:target="submit">Place order · Pay at counter</span>
@@ -107,4 +180,7 @@
         </button>
         <p>No account required. Split payments are available at the counter.</p>
+        @else
+            <button type="button" class="btn primary wide" wire:click="showCheckout" @disabled(! $orderingOpen || ! $cart)>Continue to checkout</button>
+        @endif
     </footer>
 @endif
diff --git a/resources/views/livewire/terminal.blade.php b/resources/views/livewire/terminal.blade.php
index 0052960..6ac2844 100644
--- a/resources/views/livewire/terminal.blade.php
+++ b/resources/views/livewire/terminal.blade.php
@@ -66,5 +66,5 @@
 </strong></div>@empty<div class="cart-empty"><span class="orange-icon"><x-g-icon name="shopping-bag"/></span><h3>Something delicious awaits.</h3><p>Tap a menu item to start an order.</p></div>@endforelse
 <div x-show="!payStep">
-    @include('livewire.partials.topping-picker', ['canIncrease' => (bool) $registerShift, 'canEdit' => !$complimentaryIds && $discountValue === ''])
+    @include('livewire.partials.topping-picker', ['canIncrease' => (bool) $registerShift, 'canEdit' => !$complimentaryToppingIds && !$complimentaryIds && $discountValue === ''])
 </div>
 <div x-show="payStep">
@@ -73,5 +73,5 @@
             <div class="total-row">
                 <span>{{ $quantity }} × {{ $topping->name }}</span>
-                <strong>{{ $topping->active ? '₱'.number_format($topping->price*$quantity/100, 2) : 'Unavailable' }}</strong>
+                <strong>{{ !$topping->active ? 'Unavailable' : (in_array($topping->id, $complimentaryToppingIds, true) ? 'Complimentary · ₱0.00' : '₱'.number_format($topping->price*$quantity/100, 2)) }}</strong>
             </div>
         @endif
@@ -79,5 +79,5 @@
 </div>
 </div><div class="cart-checkout"><div class="checkout-totals"><div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>
-@include('livewire.partials.topping-subtotal-breakdown')
+@include('livewire.partials.topping-subtotal-breakdown', ['freeToppingIds' => $complimentaryToppingIds])
 @if($complimentary > 0)<div class="total-row"><span>Complimentary items</span><strong>−₱{{ number_format($complimentary / 100, 2) }}</strong></div>@endif
 @if($discount > 0)<div class="total-row"><span>Discount</span><strong>−₱{{ number_format($discount / 100, 2) }}</strong></div>@endif
@@ -90,5 +90,5 @@
         <button type="button" class="btn" wire:click="requestCartDiscount(true)">Remove discount</button>
         <p class="muted">Remove the discount to make an item free.</p>
-    @elseif($complimentaryIds)
+    @elseif($complimentaryIds || $complimentaryToppingIds)
         <p><strong>Complimentary items applied</strong> · Manager approved</p>
         <button type="button" class="btn" disabled>Apply discount</button>
@@ -107,4 +107,14 @@
         </div>
     @endforeach
+    @foreach($toppings as $topping)
+        @if($topping->active && ($toppingQuantities[$topping->id] ?? 0) > 0)
+            <div class="pos-adjustment-item" wire:key="adjustment-topping-{{ $topping->id }}">
+                <strong>{{ $topping->name }} × {{ $toppingQuantities[$topping->id] }}</strong>
+                <button type="button" class="btn" wire:click="requestCartComplimentaryTopping({{ $topping->id }})" @disabled($discountValue !== '')>
+                    {{ in_array($topping->id, $complimentaryToppingIds, true) ? 'Undo free topping' : 'Make topping free' }}
+                </button>
+            </div>
+        @endif
+    @endforeach
 </details>
 @endif
ORDERLANE_PATCH
if git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
    echo "Mobile checkout and complimentary toppings are already installed."
    exit 0
fi
git apply --check "$patch_file"
if [[ "${1:-}" == "--check" ]]; then
    echo "Compatibility check passed. No files changed."
    exit 0
fi
git apply "$patch_file"
echo "Mobile checkout and complimentary toppings installed. Run php artisan view:clear."
