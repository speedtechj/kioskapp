#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 0 || ( $# -eq 1 && "${1:-}" == "--check" ) ]] || { echo "Usage: bash orderlane-optional-toppings.sh [--check]" >&2; exit 2; }
[[ -f artisan && -f app/Services/PlaceOrder.php ]] || { echo "Run from the Orderlane Laravel project folder." >&2; exit 1; }
patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat > "$patch_file" <<'ORDERLANE_PATCH'
diff --git a/app/Livewire/Concerns/SelectsToppings.php b/app/Livewire/Concerns/SelectsToppings.php
new file mode 100644
index 0000000..cd425b0
--- /dev/null
+++ b/app/Livewire/Concerns/SelectsToppings.php
@@ -0,0 +1,47 @@
+<?php
+
+namespace App\Livewire\Concerns;
+
+use App\Services\OrderToppings;
+use Illuminate\Support\Collection;
+use Livewire\Attributes\Locked;
+
+trait SelectsToppings
+{
+    #[Locked]
+    public array $toppingQuantities = [];
+
+    abstract protected function toppingBusinessId(): int;
+
+    public function changeTopping(int $id, bool $increase): void
+    {
+        $businessId = $this->toppingBusinessId();
+        $current = $this->toppingQuantities[$id] ?? 0;
+
+        if ($increase) {
+            if (! $this->cart || $current >= 99 || ($current === 0 && count($this->toppingQuantities) >= 50)
+                || ! OrderToppings::catalog($businessId)->where('id', $id)->where('active', true)->exists()) {
+                return;
+            }
+            $this->toppingQuantities[$id] = $current + 1;
+        } elseif ($current > 1) {
+            $this->toppingQuantities[$id] = $current - 1;
+        } else {
+            unset($this->toppingQuantities[$id]);
+        }
+
+        $this->resetValidation('toppings');
+    }
+
+    protected function toppingOptions(int $businessId): Collection
+    {
+        return OrderToppings::catalog($businessId)->where(function ($query) {
+            $query->where('active', true)->orWhereIn('id', array_keys($this->toppingQuantities));
+        })->orderBy('name')->get();
+    }
+
+    protected function toppingSubtotal(Collection $toppings): int
+    {
+        return $toppings->where('active', true)->sum(fn ($topping) => $topping->price * ($this->toppingQuantities[$topping->id] ?? 0));
+    }
+}
diff --git a/app/Livewire/CustomerMenu.php b/app/Livewire/CustomerMenu.php
index 3760629..609d144 100644
--- a/app/Livewire/CustomerMenu.php
+++ b/app/Livewire/CustomerMenu.php
@@ -15,2 +15,3 @@ class CustomerMenu extends Component
 {
+    use \App\Livewire\Concerns\SelectsToppings;
     use \App\Livewire\Concerns\ChecksBusinessAvailability;
@@ -50,2 +51,7 @@ class CustomerMenu extends Component
 
+    protected function toppingBusinessId(): int
+    {
+        return $this->table()->kiosk->business_id;
+    }
+
     public function add(int $id): void
@@ -68,2 +74,5 @@ class CustomerMenu extends Component
                 unset($this->cart[$id]);
+                if (! $this->cart) {
+                    $this->toppingQuantities = [];
+                }
             }
@@ -77,3 +86,3 @@ class CustomerMenu extends Component
         $table = $this->table();
-        $order = app(PlaceOrder::class)->handle($table->kiosk, $this->cart, $this->requestKey, $table, null, $this->name, $this->notes);
+        $order = app(PlaceOrder::class)->handle($table->kiosk, $this->cart, $this->requestKey, $table, null, $this->name, $this->notes, toppings: $this->toppingQuantities);
         $this->redirectRoute('tracking', ['token' => $order->slug]);
@@ -92,3 +101,5 @@ class CustomerMenu extends Component
         $cartProducts = $all->whereIn('id', array_keys($this->cart));
-        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0));
+        $toppings = $this->cart ? $this->toppingOptions($table->kiosk->business_id) : collect();
+        $toppingSubtotal = $this->toppingSubtotal($toppings);
+        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0)) + $toppingSubtotal;
         $fee = (int) round($subtotal * $table->kiosk->service_charge_bps / 10000);
@@ -97,3 +108,3 @@ class CustomerMenu extends Component
 
-        return view('livewire.customer-menu', compact('orderingOpen', 'table', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Order at '.$table->kiosk->business->name, 'manifestUrl' => route('menu.manifest', [$table->kiosk->business, $table->kiosk, $table])]);
+        return view('livewire.customer-menu', compact('toppings', 'toppingSubtotal', 'orderingOpen', 'table', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Order at '.$table->kiosk->business->name, 'manifestUrl' => route('menu.manifest', [$table->kiosk->business, $table->kiosk, $table])]);
     }
diff --git a/app/Livewire/TakeoutMenu.php b/app/Livewire/TakeoutMenu.php
index f2ccb35..492aece 100644
--- a/app/Livewire/TakeoutMenu.php
+++ b/app/Livewire/TakeoutMenu.php
@@ -16,2 +16,3 @@ class TakeoutMenu extends Component
 {
+    use \App\Livewire\Concerns\SelectsToppings;
     use \App\Livewire\Concerns\ChecksBusinessAvailability;
@@ -81,2 +82,7 @@ class TakeoutMenu extends Component
 
+    protected function toppingBusinessId(): int
+    {
+        return $this->kiosk()->business_id;
+    }
+
     public function add(int $id): void
@@ -101,2 +107,5 @@ class TakeoutMenu extends Component
                 unset($this->cart[$id]);
+                if (! $this->cart) {
+                    $this->toppingQuantities = [];
+                }
             }
@@ -110,3 +119,3 @@ class TakeoutMenu extends Component
         $kiosk = $this->kiosk();
-        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, null, null, $this->name, $this->notes);
+        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, null, null, $this->name, $this->notes, toppings: $this->toppingQuantities);
         Cookie::queue($this->trackingCookie($kiosk), $order->slug, 1440, '/', null, request()->isSecure(), true, false, 'lax');
@@ -131,3 +140,5 @@ class TakeoutMenu extends Component
         $cartProducts = $all->whereIn('id', array_keys($this->cart));
-        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0));
+        $toppings = $this->cart ? $this->toppingOptions($kiosk->business_id) : collect();
+        $toppingSubtotal = $this->toppingSubtotal($toppings);
+        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0)) + $toppingSubtotal;
         $fee = (int) round($subtotal * $kiosk->service_charge_bps / 10000);
@@ -138,3 +149,3 @@ class TakeoutMenu extends Component
 
-        return view('livewire.takeout-menu', compact('activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
+        return view('livewire.takeout-menu', compact('toppings', 'toppingSubtotal', 'activeOrder', 'orderingOpen', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'languages', 'language'))->layout('layouts.app', ['title' => 'Takeout at '.$kiosk->business->name, 'manifestUrl' => route('takeout.manifest', [$kiosk->business, $kiosk])]);
     }
diff --git a/app/Livewire/Terminal.php b/app/Livewire/Terminal.php
index 8b74a40..db7da93 100644
--- a/app/Livewire/Terminal.php
+++ b/app/Livewire/Terminal.php
@@ -21,2 +21,3 @@ class Terminal extends Component
 {
+    use \App\Livewire\Concerns\SelectsToppings;
     use \App\Livewire\Concerns\ChecksBusinessAvailability;
@@ -86,2 +87,10 @@ class Terminal extends Component
 
+    protected function toppingBusinessId(): int
+    {
+        Access::authorize($this->businessId, 'pos.use', $this->kioskId);
+        $this->ensureCartEditable();
+
+        return $this->businessId;
+    }
+
     public function add(int $id): void
@@ -104,2 +113,5 @@ class Terminal extends Component
                 unset($this->cart[$id]);
+                if (! $this->cart) {
+                    $this->toppingQuantities = [];
+                }
                 $this->complimentaryIds = array_values(array_diff($this->complimentaryIds, [$id]));
@@ -158,3 +170,4 @@ class Terminal extends Component
         $lineTotal = fn ($product) => $product->price * (int) $this->cart[$product->id];
-        $subtotal = $products->sum($lineTotal);
+        $toppingLines = \App\Services\OrderToppings::lines($this->businessId, $this->toppingQuantities);
+        $subtotal = $products->sum($lineTotal) + array_sum(array_column($toppingLines, 'total'));
         $before = OrderAdjustments::totals($subtotal, $products->whereIn('id', $this->complimentaryIds)->sum($lineTotal), $this->discountType, $this->discountValue, $kiosk->service_charge_bps)['total'];
@@ -170,3 +183,3 @@ class Terminal extends Component
             'type' => $type, 'value' => $value, 'ids' => $ids,
-            'fingerprint' => PlaceOrder::adjustmentFingerprint($this->cart, $prices, $kiosk->service_charge_bps),
+            'fingerprint' => PlaceOrder::adjustmentFingerprint($this->cart, $prices, $kiosk->service_charge_bps, $toppingLines),
         ];
@@ -201,3 +214,3 @@ class Terminal extends Component
         $cashReceived = $method === 'cash' && ($requireCashReceived || $this->cashReceived !== '') ? $this->cashReceived : null;
-        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, $table, $method, payments: $rows, cashReceived: $cashReceived, complimentaryIds: $this->complimentaryIds, discountType: $this->discountType, discountValue: $this->discountValue, adjustmentApprovals: $this->adjustmentApprovals, approvedCart: $this->approvedCart);
+        $order = app(PlaceOrder::class)->handle($kiosk, $this->cart, $this->requestKey, $table, $method, payments: $rows, cashReceived: $cashReceived, complimentaryIds: $this->complimentaryIds, discountType: $this->discountType, discountValue: $this->discountValue, adjustmentApprovals: $this->adjustmentApprovals, approvedCart: $this->approvedCart, toppings: $this->toppingQuantities);
         $this->finishCheckout($order);
@@ -222,2 +235,3 @@ class Terminal extends Component
             approvedCart: $this->approvedCart,
+            toppings: $this->toppingQuantities,
         );
@@ -232,2 +246,3 @@ class Terminal extends Component
         $this->cart = [];
+        $this->toppingQuantities = [];
         $this->reset('cashReceived', 'complimentaryIds', 'discountType', 'discountValue', 'proposedDiscountType', 'proposedDiscountValue', 'adjustmentApprovals', 'approvedCart');
@@ -272,3 +287,5 @@ class Terminal extends Component
         $cartProducts = $all->whereIn('id', array_keys($this->cart));
-        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0));
+        $toppings = $this->cart ? $this->toppingOptions($kiosk->business_id) : collect();
+        $toppingSubtotal = $this->toppingSubtotal($toppings);
+        $subtotal = $cartProducts->sum(fn ($p) => $p->price * ($this->cart[$p->id] ?? 0)) + $toppingSubtotal;
         $complimentary = $cartProducts->whereIn('id', $this->complimentaryIds)
@@ -286,3 +303,3 @@ class Terminal extends Component
 
-        return view('livewire.terminal', compact('business', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'incoming', 'discount', 'complimentary', 'totalDue', 'adjustmentsValid', 'registerShift'))->layout('layouts.app', ['title' => 'Point of sale']);
+        return view('livewire.terminal', compact('toppings', 'toppingSubtotal', 'business', 'kiosk', 'products', 'categories', 'cartProducts', 'subtotal', 'fee', 'incoming', 'discount', 'complimentary', 'totalDue', 'adjustmentsValid', 'registerShift'))->layout('layouts.app', ['title' => 'Point of sale']);
     }
diff --git a/app/Livewire/Workspace.php b/app/Livewire/Workspace.php
index 2d807e7..0d4e268 100644
--- a/app/Livewire/Workspace.php
+++ b/app/Livewire/Workspace.php
@@ -67,3 +67,3 @@ class Workspace extends Component
 
-    private const SECTIONS = ['overview' => 'dashboard.view', 'orders' => 'orders.view', 'menu' => 'menu.manage', 'inventory' => 'inventory.manage', 'kiosks' => 'kiosks.manage', 'staff' => 'staff.manage', 'reports' => 'reports.view'];
+    private const SECTIONS = ['overview' => 'dashboard.view', 'orders' => 'orders.view', 'menu' => 'menu.manage', 'toppings' => 'menu.manage', 'inventory' => 'inventory.manage', 'kiosks' => 'kiosks.manage', 'staff' => 'staff.manage', 'reports' => 'reports.view'];
 
@@ -76,2 +76,50 @@ class Workspace extends Component
 
+    public ?int $toppingId = null;
+
+    public bool $toppingEditorOpen = false;
+
+    public array $toppingForm = [];
+
+    public function editTopping(?int $id = null): void
+    {
+        Access::authorize($this->businessId, 'menu.manage');
+        $topping = $id === null ? null : \App\Services\OrderToppings::catalog($this->businessId)->where('id', $id)->first();
+        abort_if($id !== null && ! $topping, 404);
+
+        $this->toppingId = $id;
+        $this->toppingForm = [
+            'name' => $topping->name ?? '',
+            'price' => $topping ? \App\Services\Money::input($topping->price) : '',
+            'active' => $topping ? (bool) $topping->active : true,
+        ];
+        $this->resetValidation();
+        $this->toppingEditorOpen = true;
+    }
+
+    public function saveTopping(): void
+    {
+        Access::authorize($this->businessId, 'menu.manage');
+        $form = $this->validate([
+            'toppingForm.name' => 'required|string|max:100',
+            'toppingForm.price' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D', 'numeric', 'min:0.01'],
+            'toppingForm.active' => 'required|boolean',
+        ])['toppingForm'];
+        $data = [
+            'name' => trim($form['name']),
+            'price' => \App\Services\Money::cents((string) $form['price']),
+            'active' => $form['active'],
+        ];
+
+        $catalog = \App\Services\OrderToppings::catalog($this->businessId);
+        if ($this->toppingId === null) {
+            $catalog->insert($data + ['business_id' => $this->businessId]);
+        } else {
+            $row = $catalog->where('id', $this->toppingId);
+            abort_unless($row->exists(), 404);
+            $row->update($data);
+        }
+
+        $this->toppingEditorOpen = false;
+    }
+
     public function navigate(string $section): void
@@ -463,3 +511,5 @@ class Workspace extends Component
 
-        return view('livewire.workspace', compact('business', 'kiosks', 'sales', 'count', 'average', 'activeTables', 'orderReport', 'recent', 'products', 'staff', 'inventory', 'tables', 'best', 'chart'))->layout('layouts.app', ['title' => ucfirst($this->section)]);
+        $toppings = $this->section === 'toppings' ? \App\Services\OrderToppings::catalog($this->businessId)->orderBy('name')->get() : collect();
+
+        return view('livewire.workspace', compact('toppings', 'business', 'kiosks', 'sales', 'count', 'average', 'activeTables', 'orderReport', 'recent', 'products', 'staff', 'inventory', 'tables', 'best', 'chart'))->layout('layouts.app', ['title' => ucfirst($this->section)]);
     }
diff --git a/app/Models/Order.php b/app/Models/Order.php
index 436bddc..ad10339 100644
--- a/app/Models/Order.php
+++ b/app/Models/Order.php
@@ -17,3 +17,3 @@ class Order extends Model
     {
-        return ['adjustment_approvals' => 'array'];
+        return ['adjustment_approvals' => 'array', 'topping_lines' => 'array'];
     }
diff --git a/app/Services/OrderToppings.php b/app/Services/OrderToppings.php
new file mode 100644
index 0000000..d4e721f
--- /dev/null
+++ b/app/Services/OrderToppings.php
@@ -0,0 +1,43 @@
+<?php
+
+namespace App\Services;
+
+use Illuminate\Database\Query\Builder;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\ValidationException;
+
+class OrderToppings
+{
+    public static function catalog(int $businessId): Builder
+    {
+        return DB::table('toppings')->where('business_id', $businessId);
+    }
+
+    public static function lines(int $businessId, array $quantities, bool $lock = false): array
+    {
+        if ($quantities === []) {
+            return [];
+        }
+
+        foreach ($quantities as $id => $quantity) {
+            if (! is_int($id) || $id < 1 || ! is_int($quantity) || $quantity < 1 || $quantity > 99 || count($quantities) > 50) {
+                throw ValidationException::withMessages(['toppings' => 'Choose valid topping quantities.']);
+            }
+        }
+
+        $rows = self::catalog($businessId)->where('active', true)->whereIn('id', array_keys($quantities))
+            ->orderBy('id')->when($lock, fn (Builder $query) => $query->lockForUpdate())->get();
+
+        if ($rows->count() !== count($quantities)) {
+            throw ValidationException::withMessages(['toppings' => 'A selected topping is unavailable. Remove it to continue.']);
+        }
+
+        return $rows->map(fn ($row) => [
+            'id' => $row->id,
+            'name' => $row->name,
+            'unit_price' => $row->price,
+            'quantity' => $quantities[$row->id],
+            'total' => $row->price * $quantities[$row->id],
+        ])->all();
+    }
+}
diff --git a/app/Services/PlaceOrder.php b/app/Services/PlaceOrder.php
index 1a19ae9..8b82e3a 100644
--- a/app/Services/PlaceOrder.php
+++ b/app/Services/PlaceOrder.php
@@ -13,3 +13,3 @@ class PlaceOrder
 {
-    public static function adjustmentFingerprint(array $cart, array $prices, int $serviceRate): string
+    public static function adjustmentFingerprint(array $cart, array $prices, int $serviceRate, array $toppingLines = []): string
     {
@@ -20,6 +20,6 @@ class PlaceOrder
 
-        return hash('sha256', json_encode([$cart, $prices, $serviceRate], JSON_THROW_ON_ERROR));
+        return hash('sha256', json_encode($toppingLines === [] ? [$cart, $prices, $serviceRate] : [$cart, $prices, $serviceRate, $toppingLines], JSON_THROW_ON_ERROR));
     }
 
-    public function handle(Kiosk $kiosk, array $cart, string $requestKey, ?DiningTable $table = null, ?string $method = null, ?string $name = null, ?string $notes = null, ?array $payments = null, bool $payAtPickup = false, ?string $cashReceived = null, array $complimentaryIds = [], string $discountType = 'percent', string $discountValue = '', array $adjustmentApprovals = [], string $approvedCart = ''): Order
+    public function handle(Kiosk $kiosk, array $cart, string $requestKey, ?DiningTable $table = null, ?string $method = null, ?string $name = null, ?string $notes = null, ?array $payments = null, bool $payAtPickup = false, ?string $cashReceived = null, array $complimentaryIds = [], string $discountType = 'percent', string $discountValue = '', array $adjustmentApprovals = [], string $approvedCart = '', array $toppings = []): Order
     {
@@ -50,3 +50,3 @@ class PlaceOrder
 
-        return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart) {
+        return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart, $toppings) {
             BusinessAvailability::enforce($kiosk->business_id, lock: true);
@@ -69,2 +69,3 @@ class PlaceOrder
             }
+            $toppingLines = OrderToppings::lines($kiosk->business_id, $toppings, lock: true);
             if ($complimentaryIds && $discountValue !== '') {
@@ -73,3 +74,3 @@ class PlaceOrder
             if (($complimentaryIds || $discountValue !== '') && (! $adjustmentApprovals
-                || $approvedCart !== self::adjustmentFingerprint($cart, $products->pluck('price', 'id')->all(), $locked->service_charge_bps))) {
+                || $approvedCart !== self::adjustmentFingerprint($cart, $products->pluck('price', 'id')->all(), $locked->service_charge_bps, $toppingLines))) {
                 throw ValidationException::withMessages(['adjustment' => 'Get manager approval for the current cart before checkout.']);
@@ -77,3 +78,3 @@ class PlaceOrder
 
-            $subtotal = 0;
+            $subtotal = array_sum(array_column($toppingLines, 'total'));
             $items = [];
@@ -98,2 +99,5 @@ class PlaceOrder
             }
+            if ($toppingLines) {
+                $order->forceFill(['topping_lines' => $toppingLines])->save();
+            }
             $order->items()->createMany($items);
diff --git a/database/migrations/2026_11_21_120000_add_order_toppings.php b/database/migrations/2026_11_21_120000_add_order_toppings.php
new file mode 100644
index 0000000..4218748
--- /dev/null
+++ b/database/migrations/2026_11_21_120000_add_order_toppings.php
@@ -0,0 +1,30 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('toppings', function (Blueprint $table) {
+            $table->id();
+            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
+            $table->string('name', 100);
+            $table->unsignedInteger('price');
+            $table->boolean('active')->default(true);
+            $table->index(['business_id', 'active']);
+        });
+
+        Schema::table('orders', function (Blueprint $table) {
+            $table->json('topping_lines')->nullable();
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('topping_lines'));
+        Schema::dropIfExists('toppings');
+    }
+};
diff --git a/public/branding/order-toppings.css b/public/branding/order-toppings.css
new file mode 100644
index 0000000..33a0c9f
--- /dev/null
+++ b/public/branding/order-toppings.css
@@ -0,0 +1,48 @@
+.order-toppings {
+    margin-block: 12px;
+    border: 1px solid var(--line);
+    border-radius: 10px;
+    background: var(--paper);
+}
+
+.order-toppings summary {
+    padding: 12px;
+    cursor: pointer;
+    font-weight: 650;
+}
+
+.order-topping {
+    display: flex;
+    justify-content: space-between;
+    align-items: center;
+    gap: 8px;
+    border-top: 1px solid var(--line);
+    padding: 8px;
+    overflow-wrap: anywhere;
+}
+
+.order-topping small {
+    display: block;
+    color: #555a50;
+}
+
+.order-topping-quantity {
+    display: flex;
+    flex-shrink: 0;
+    align-items: center;
+    gap: 5px;
+    text-align: center;
+}
+
+.order-topping-quantity .btn {
+    justify-content: center;
+    width: 44px;
+    min-height: 44px;
+    padding: 0;
+    font-size: 1.2rem;
+}
+
+.order-toppings .total-row {
+    padding: 8px;
+    margin: 0;
+}
diff --git a/resources/views/components/kitchen-order-ticket.blade.php b/resources/views/components/kitchen-order-ticket.blade.php
index b16ef77..0e627b1 100644
--- a/resources/views/components/kitchen-order-ticket.blade.php
+++ b/resources/views/components/kitchen-order-ticket.blade.php
@@ -22,2 +22,4 @@
         @endforeach
+@include('livewire.partials.saved-topping-lines', ['kitchen' => true])
+
     </ul>
diff --git a/resources/views/components/sidebar.blade.php b/resources/views/components/sidebar.blade.php
index 443094e..7cd19f7 100644
--- a/resources/views/components/sidebar.blade.php
+++ b/resources/views/components/sidebar.blade.php
@@ -45,3 +45,3 @@
 <div class="nav-caption">WORKSPACE</div><nav>
-@foreach(['overview'=>['home','Overview','dashboard.view'],'orders'=>['shopping-bag','Orders','orders.view'],'menu'=>['squares-2x2','Menu','menu.manage'],'inventory'=>['cube','Inventory','inventory.manage'],'kiosks'=>['building-storefront','Kiosks & QR','kiosks.manage'],'staff'=>['users','Team & access','staff.manage'],'reports'=>['chart-bar','Reports','reports.view']] as $key=>[$icon,$label,$permission])
+@foreach(['overview'=>['home','Overview','dashboard.view'],'orders'=>['shopping-bag','Orders','orders.view'],'menu'=>['squares-2x2','Menu','menu.manage'],'toppings'=>['cube','Toppings','menu.manage'],'inventory'=>['cube','Inventory','inventory.manage'],'kiosks'=>['building-storefront','Kiosks & QR','kiosks.manage'],'staff'=>['users','Team & access','staff.manage'],'reports'=>['chart-bar','Reports','reports.view']] as $key=>[$icon,$label,$permission])
 @if(\App\Services\Access::allows(auth()->user(),$business->id,$permission))
diff --git a/resources/views/livewire/customer-menu.blade.php b/resources/views/livewire/customer-menu.blade.php
index 4a40cf7..d7cb2a1 100644
--- a/resources/views/livewire/customer-menu.blade.php
+++ b/resources/views/livewire/customer-menu.blade.php
@@ -16,2 +16,4 @@
 @endif
-<div class="customer-products">@forelse($products as $p)<article class="customer-product" wire:key="menu-{{ $p->id }}"><img src="{{ $p->image }}" alt="{{ $p->menuText('name', $language) }}"><div><h3>{{ $p->menuText('name', $language) }}</h3><p>{{ $p->menuText('description', $language) }}</p><div class="customer-product-bottom"><strong>₱{{ number_format($p->price/100,2) }}</strong><button class="btn add-small" wire:click="add({{ $p->id }})" @disabled(! $orderingOpen || $p->pivot->stock<1)>{{ $p->pivot->stock>0?'+ Add':'Sold out' }}{{ isset($cart[$p->id])?' · '.$cart[$p->id]:'' }}</button></div></div></article>@empty<p class="empty">No matching items.</p>@endforelse</div><footer class="customer-footer"><x-g-icon name="heart"/>Good food. Great company. Gather.</footer><div class="basket-bar"><button class="btn primary wide" wire:click="$set('basketOpen',true)" @disabled(! $orderingOpen || ! $cart)>@if(! $orderingOpen) Place order @else<span><x-g-icon name="shopping-bag"/>View basket · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}</span><strong>₱{{ number_format(($subtotal+$fee)/100,2) }} →</strong>@endif</button></div>@if($basketOpen)<dialog class="modal gather-dialog gd-content basket-modal" aria-label="Review your basket" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('basketOpen',false)"><div class="section-line"><h2>Your good choices.</h2><button class="icon-btn" wire:click="$set('basketOpen',false)" aria-label="Close basket"><x-g-icon name="x-mark"/></button></div><p class="muted">{{ $table->kiosk->name }} · {{ $table->name }}</p>@foreach($cartProducts as $p)<div class="cart-item"><img src="{{ $p->image }}" alt=""><div><h3>{{ $p->menuText('name', $language) }}</h3><div class="quantity"><button wire:click="decrease({{ $p->id }})" aria-label="Remove one">−</button><span>{{ $cart[$p->id] }}</span><button wire:click="add({{ $p->id }})" aria-label="Add one" @disabled(! $orderingOpen)>+</button></div></div><strong>₱{{ number_format($p->price*$cart[$p->id]/100,2) }}</strong></div>@endforeach<label>Your name <span class="muted">(optional)</span><input wire:model="name" maxlength="80" placeholder="What should we call you?"></label><label>Order notes <span class="muted">(optional)</span><textarea wire:model="notes" maxlength="500" placeholder="Anything we should know?"></textarea></label><div class="total-row"><span>Subtotal</span><strong>₱{{ number_format($subtotal/100,2) }}</strong></div><div class="total-row"><span>Service charge</span><span>₱{{ number_format($fee/100,2) }}</span></div><div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div><p class="payment-note">Pay at the counter. Your order is sent to the kitchen when submitted.</p>@foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)<p class="error">{{ $error }}</p>@endforeach<button wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="btn primary wide" @disabled(! $orderingOpen || ! $cart)>Place order <x-g-icon name="arrow-right"/></button></dialog>@endif</main>
\ No newline at end of file
+<div class="customer-products">@forelse($products as $p)<article class="customer-product" wire:key="menu-{{ $p->id }}"><img src="{{ $p->image }}" alt="{{ $p->menuText('name', $language) }}"><div><h3>{{ $p->menuText('name', $language) }}</h3><p>{{ $p->menuText('description', $language) }}</p><div class="customer-product-bottom"><strong>₱{{ number_format($p->price/100,2) }}</strong><button class="btn add-small" wire:click="add({{ $p->id }})" @disabled(! $orderingOpen || $p->pivot->stock<1)>{{ $p->pivot->stock>0?'+ Add':'Sold out' }}{{ isset($cart[$p->id])?' · '.$cart[$p->id]:'' }}</button></div></div></article>@empty<p class="empty">No matching items.</p>@endforelse</div><footer class="customer-footer"><x-g-icon name="heart"/>Good food. Great company. Gather.</footer><div class="basket-bar"><button class="btn primary wide" wire:click="$set('basketOpen',true)" @disabled(! $orderingOpen || ! $cart)>@if(! $orderingOpen) Place order @else<span><x-g-icon name="shopping-bag"/>View basket · {{ array_sum($cart) }} {{ \Illuminate\Support\Str::plural('item', array_sum($cart)) }}</span><strong>₱{{ number_format(($subtotal+$fee)/100,2) }} →</strong>@endif</button></div>@if($basketOpen)<dialog class="modal gather-dialog gd-content basket-modal" aria-label="Review your basket" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('basketOpen',false)"><div class="section-line"><h2>Your good choices.</h2><button class="icon-btn" wire:click="$set('basketOpen',false)" aria-label="Close basket"><x-g-icon name="x-mark"/></button></div><p class="muted">{{ $table->kiosk->name }} · {{ $table->name }}</p>@foreach($cartProducts as $p)<div class="cart-item"><img src="{{ $p->image }}" alt=""><div><h3>{{ $p->menuText('name', $language) }}</h3><div class="quantity"><button wire:click="decrease({{ $p->id }})" aria-label="Remove one">−</button><span>{{ $cart[$p->id] }}</span><button wire:click="add({{ $p->id }})" aria-label="Add one" @disabled(! $orderingOpen)>+</button></div></div><strong>₱{{ number_format($p->price*$cart[$p->id]/100,2) }}</strong></div>@endforeach
+@include('livewire.partials.topping-picker', ['canIncrease' => $orderingOpen])
+<label>Your name <span class="muted">(optional)</span><input wire:model="name" maxlength="80" placeholder="What should we call you?"></label><label>Order notes <span class="muted">(optional)</span><textarea wire:model="notes" maxlength="500" placeholder="Anything we should know?"></textarea></label><div class="total-row"><span>Subtotal</span><strong>₱{{ number_format($subtotal/100,2) }}</strong></div><div class="total-row"><span>Service charge</span><span>₱{{ number_format($fee/100,2) }}</span></div><div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format(($subtotal+$fee)/100,2) }}</strong></div><p class="payment-note">Pay at the counter. Your order is sent to the kitchen when submitted.</p>@foreach(collect($errors->getMessages())->except('ordering')->flatten() as $error)<p class="error">{{ $error }}</p>@endforeach<button wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="btn primary wide" @disabled(! $orderingOpen || ! $cart)>Place order <x-g-icon name="arrow-right"/></button></dialog>@endif</main>
\ No newline at end of file
diff --git a/resources/views/livewire/customer-order.blade.php b/resources/views/livewire/customer-order.blade.php
index 42392e8..3eb4780 100644
--- a/resources/views/livewire/customer-order.blade.php
+++ b/resources/views/livewire/customer-order.blade.php
@@ -22,3 +22,5 @@
 <section class="takeout-totals payment-receipt">@foreach($order->payments as $payment)<div class="total-row"><span>{{ ['cash'=>'Cash','gcash'=>'GCash','card'=>'Card','legacy'=>'Previous payment'][$payment->method] ?? ucfirst($payment->method) }}</span><span>₱{{ number_format($payment->amount/100,2) }}</span></div>@endforeach<div class="total-row grand-total"><strong>Total paid</strong><strong>₱{{ number_format($paid/100,2) }}</strong></div>@if($remaining===0)<div class="fully-paid"><x-g-icon name="check-circle"/>Fully paid</div>@else<div class="total-row"><span>Remaining balance</span><strong>₱{{ number_format($remaining/100,2) }}</strong></div><button class="btn primary wide" wire:click="$set('screen','payment')">View split payment & balance</button>@endif</section>
-<details class="order-details" @if($order->complimentary_amount || $order->discount_amount) open @endif><summary>View order details</summary>@foreach($order->items as $item)<div class="total-row"><span>{{ $item->quantity }} × {{ $item->name }} @if($item->complimentary)<strong>· Complimentary</strong>@endif</span><strong>₱{{ number_format($item->total/100,2) }}</strong></div>@endforeach<x-order-adjustment-totals :order="$order" /><div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div><button class="btn wide" wire:click="$set('screen','payment')">Payment breakdown</button></details>
+<details class="order-details" @if($order->complimentary_amount || $order->discount_amount) open @endif><summary>View order details</summary>@foreach($order->items as $item)<div class="total-row"><span>{{ $item->quantity }} × {{ $item->name }} @if($item->complimentary)<strong>· Complimentary</strong>@endif</span><strong>₱{{ number_format($item->total/100,2) }}</strong></div>@endforeach
+@include('livewire.partials.saved-topping-lines')
+<x-order-adjustment-totals :order="$order" /><div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div><button class="btn wide" wire:click="$set('screen','payment')">Payment breakdown</button></details>
 @if($order->table)<a class="btn wide takeout-back" href="{{ route('menu',[$order->kiosk->business,$order->kiosk,$order->table]) }}">Back to menu</a>@elseif($order->kiosk->takeout_token)<a class="btn wide takeout-back" href="{{ route('takeout',[$order->kiosk->business,$order->kiosk,'browse'=>1]) }}">Back to menu</a>@endif
diff --git a/resources/views/livewire/partials/saved-topping-lines.blade.php b/resources/views/livewire/partials/saved-topping-lines.blade.php
new file mode 100644
index 0000000..e625011
--- /dev/null
+++ b/resources/views/livewire/partials/saved-topping-lines.blade.php
@@ -0,0 +1,6 @@
+@foreach($order->topping_lines ?? [] as $topping)
+    <div class="{{ ($kitchen ?? false) ? 'ticket-item' : 'total-row' }}">
+        <span>{{ $topping['quantity'] }} × {{ $topping['name'] }} (extra)</span>
+        @unless($kitchen ?? false)<strong>₱{{ number_format($topping['total'] / 100, 2) }}</strong>@endunless
+    </div>
+@endforeach
diff --git a/resources/views/livewire/partials/topping-picker.blade.php b/resources/views/livewire/partials/topping-picker.blade.php
new file mode 100644
index 0000000..25ee8ae
--- /dev/null
+++ b/resources/views/livewire/partials/topping-picker.blade.php
@@ -0,0 +1,25 @@
+@if($toppings->isNotEmpty())
+    @assets
+        <link rel="stylesheet" href="{{ asset('branding/order-toppings.css') }}">
+    @endassets
+    <details class="order-toppings" wire:key="order-toppings" wire:ignore.self>
+        <summary>+ Add toppings (optional)</summary>
+        @foreach($toppings as $topping)
+            @php($quantity = $toppingQuantities[$topping->id] ?? 0)
+            <div class="order-topping" wire:key="order-topping-{{ $topping->id }}">
+                <div>
+                    <strong>{{ $topping->name }}</strong>
+                    <small>{{ $topping->active ? '₱'.number_format($topping->price / 100, 2).' per piece' : 'Unavailable' }}</small>
+                </div>
+                <div class="order-topping-quantity">
+                    <button type="button" class="btn" aria-label="Remove one {{ $topping->name }}" wire:click="changeTopping({{ $topping->id }}, false)" @disabled(!$quantity || !($canEdit ?? true))>−</button>
+                    <span aria-live="polite">{{ $quantity }}</span>
+                    <button type="button" class="btn" aria-label="Add one {{ $topping->name }}" wire:click="changeTopping({{ $topping->id }}, true)" @disabled(!$topping->active || !$canIncrease || !($canEdit ?? true) || $quantity >= 99)>+</button>
+                </div>
+            </div>
+        @endforeach
+        @if($toppingSubtotal > 0)
+            <div class="total-row"><span>Toppings subtotal</span><strong>₱{{ number_format($toppingSubtotal / 100, 2) }}</strong></div>
+        @endif
+    </details>
+@endif
diff --git a/resources/views/livewire/partials/toppings-admin.blade.php b/resources/views/livewire/partials/toppings-admin.blade.php
new file mode 100644
index 0000000..48cbd29
--- /dev/null
+++ b/resources/views/livewire/partials/toppings-admin.blade.php
@@ -0,0 +1,35 @@
+<section class="card">
+    <div class="section-line">
+        <h2>Toppings</h2>
+        <button type="button" class="btn primary" wire:click="editTopping">+ Add topping</button>
+    </div>
+    <div class="table-scroll">
+        <table>
+            <thead><tr><th>Topping</th><th>Price per piece (PHP)</th><th>Status</th><th>Action</th></tr></thead>
+            <tbody>
+                @forelse($toppings as $topping)
+                    <tr wire:key="topping-admin-{{ $topping->id }}">
+                        <td>{{ $topping->name }}</td>
+                        <td>₱{{ number_format($topping->price / 100, 2) }}</td>
+                        <td>{{ $topping->active ? 'Active' : 'Inactive' }}</td>
+                        <td><button type="button" class="btn" wire:click="editTopping({{ $topping->id }})">Edit</button></td>
+                    </tr>
+                @empty
+                    <tr><td colspan="4">No toppings yet.</td></tr>
+                @endforelse
+            </tbody>
+        </table>
+    </div>
+    @if($toppingEditorOpen)
+        <form wire:submit="saveTopping" class="card">
+            <h3>{{ $toppingId ? 'Edit topping' : 'Add topping' }}</h3>
+            <label>Topping name <input wire:model="toppingForm.name" maxlength="100" required></label>
+            @error('toppingForm.name')<p class="error" role="alert">{{ $message }}</p>@enderror
+            <label>Price per piece (PHP) <input wire:model="toppingForm.price" type="number" min="0.01" step="0.01" required></label>
+            @error('toppingForm.price')<p class="error" role="alert">{{ $message }}</p>@enderror
+            <label class="check-label"><input type="checkbox" wire:model="toppingForm.active"> Active</label>
+            <button type="button" class="btn" wire:click="$set('toppingEditorOpen', false)">Cancel</button>
+            <button type="submit" class="btn primary" wire:loading.attr="disabled" wire:target="saveTopping">Save topping</button>
+        </form>
+    @endif
+</section>
diff --git a/resources/views/livewire/payment-desk.blade.php b/resources/views/livewire/payment-desk.blade.php
index 30a6fe3..53d2170 100644
--- a/resources/views/livewire/payment-desk.blade.php
+++ b/resources/views/livewire/payment-desk.blade.php
@@ -69,3 +69,5 @@
 <p class="payment-note">You may record a partial payment now, then collect the remaining balance later. Overpayments are rejected.</p><button class="btn primary wide" wire:loading.attr="disabled" wire:target="record">Confirm collected payments <x-g-icon name="check"/></button></form>@else<div class="empty"><span class="success-icon" style="margin-inline:auto"><x-g-icon name="check"/></span><h2>Fully paid.</h2><p>There is no remaining balance to collect.</p></div>@endif</section>
-<aside><section class="card"><h2>Order summary</h2><div style="margin-top:22px">@foreach($order->items as $item)<div class="total-row"><span>{{ $item->quantity }} × {{ $item->name }} @if($item->complimentary)<strong>· Complimentary</strong>@endif</span><strong>₱{{ number_format($item->total/100,2) }}</strong></div>@endforeach<x-order-adjustment-totals :order="$order" /><div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div><div class="total-row"><span>Paid</span><strong>₱{{ number_format($order->paidAmount()/100,2) }}</strong></div><div class="total-row"><span>Remaining</span><strong>₱{{ number_format($order->remainingAmount()/100,2) }}</strong></div></div><a class="text-link" href="{{ route('tracking',['token'=>$order->slug,'view'=>'payment']) }}" target="_blank">Customer payment view ↗</a></section>
+<aside><section class="card"><h2>Order summary</h2><div style="margin-top:22px">@foreach($order->items as $item)<div class="total-row"><span>{{ $item->quantity }} × {{ $item->name }} @if($item->complimentary)<strong>· Complimentary</strong>@endif</span><strong>₱{{ number_format($item->total/100,2) }}</strong></div>@endforeach
+@include('livewire.partials.saved-topping-lines')
+<x-order-adjustment-totals :order="$order" /><div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Order total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div><div class="total-row"><span>Paid</span><strong>₱{{ number_format($order->paidAmount()/100,2) }}</strong></div><div class="total-row"><span>Remaining</span><strong>₱{{ number_format($order->remainingAmount()/100,2) }}</strong></div></div><a class="text-link" href="{{ route('tracking',['token'=>$order->slug,'view'=>'payment']) }}" target="_blank">Customer payment view ↗</a></section>
 <section class="card" style="margin-top:20px"><h2>Confirmed payments</h2>@forelse($order->payments as $payment)<div class="confirmed-payment"><div><strong>{{ $payment->method==='gcash'?'GCash':ucfirst($payment->method) }}</strong><small>{{ $payment->confirmed_at?->format('M j, g:i a') }}</small>@if($payment->reference)<small>Ref: {{ $payment->reference }}</small>@endif</div><strong>₱{{ number_format($payment->amount/100,2) }}</strong></div>@empty<p class="muted" style="margin-top:15px">No payments recorded yet.</p>@endforelse</section></aside></div>
diff --git a/resources/views/livewire/takeout-menu.blade.php b/resources/views/livewire/takeout-menu.blade.php
index dc0ca86..e9e4399 100644
--- a/resources/views/livewire/takeout-menu.blade.php
+++ b/resources/views/livewire/takeout-menu.blade.php
@@ -79,2 +79,4 @@
 
+            @include('livewire.partials.topping-picker', ['canIncrease' => $orderingOpen])
+
             <label>Name for pickup <span class="muted">(optional)</span>
diff --git a/resources/views/livewire/terminal.blade.php b/resources/views/livewire/terminal.blade.php
index 34cf70f..e5da501 100644
--- a/resources/views/livewire/terminal.blade.php
+++ b/resources/views/livewire/terminal.blade.php
@@ -65,3 +65,17 @@
 @endif
-</strong></div>@empty<div class="cart-empty"><span class="orange-icon"><x-g-icon name="shopping-bag"/></span><h3>Something delicious awaits.</h3><p>Tap a menu item to start an order.</p></div>@endforelse</div><div class="cart-checkout"><div class="checkout-totals"><div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>@if($complimentary > 0)<div class="total-row"><span>Complimentary items</span><strong>−₱{{ number_format($complimentary / 100, 2) }}</strong></div>@endif
+</strong></div>@empty<div class="cart-empty"><span class="orange-icon"><x-g-icon name="shopping-bag"/></span><h3>Something delicious awaits.</h3><p>Tap a menu item to start an order.</p></div>@endforelse
+<div x-show="!payStep">
+    @include('livewire.partials.topping-picker', ['canIncrease' => (bool) $registerShift, 'canEdit' => !$complimentaryIds && $discountValue === ''])
+</div>
+<div x-show="payStep">
+    @foreach($toppings as $topping)
+        @if($quantity = $toppingQuantities[$topping->id] ?? 0)
+            <div class="total-row">
+                <span>{{ $quantity }} × {{ $topping->name }}</span>
+                <strong>{{ $topping->active ? '₱'.number_format($topping->price*$quantity/100, 2) : 'Unavailable' }}</strong>
+            </div>
+        @endif
+    @endforeach
+</div>
+</div><div class="cart-checkout"><div class="checkout-totals"><div class="total-row"><span>Subtotal</span><span>₱{{ number_format($subtotal/100,2) }}</span></div>@if($complimentary > 0)<div class="total-row"><span>Complimentary items</span><strong>−₱{{ number_format($complimentary / 100, 2) }}</strong></div>@endif
 @if($discount > 0)<div class="total-row"><span>Discount</span><strong>−₱{{ number_format($discount / 100, 2) }}</strong></div>@endif
diff --git a/resources/views/livewire/workspace.blade.php b/resources/views/livewire/workspace.blade.php
--- a/resources/views/livewire/workspace.blade.php
+++ b/resources/views/livewire/workspace.blade.php
@@ -3,3 +3,3 @@
 @endassets
-<div class="app-shell" wire:poll.30s x-data="{navOpen:false}"><x-sidebar :business="$business" :mode="$section"/><main class="main"><header class="topbar"><div class="heading-row"><button class="mobile-only icon-btn" @click="navOpen=true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button><div><span class="eyebrow">YOUR RESTAURANT, AT A GLANCE</span><h1>{{ $section==='overview'?'Good '.(now()->hour<12?'morning':(now()->hour<18?'afternoon':'evening')).', '.explode(' ',auth()->user()->name)[0]:['orders'=>'Orders & cashier sales','menu'=>'Made for your menu','inventory'=>'Keep the good stuff stocked','kiosks'=>'Your kiosks, together','staff'=>'A team that clicks','reports'=>'The bigger picture'][$section] }}<span class="brand-dot">.</span></h1><p class="muted">{{ $section==='overview'?"Here’s what’s happening at ".$business->name.' today.':'One workspace. Everything in its place.' }}</p></div></div><div class="topbar-actions">@if($section !== 'orders')<div class="branch-select"><x-g-icon name="building-storefront"/><select wire:model.live="selectedKiosk" aria-label="Filter by kiosk"><option value="0">All kiosks</option>@foreach($kiosks as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach</select></div>@endif<a href="{{ route('home') }}" class="avatar" title="Switch business">{{ substr(auth()->user()->name,0,1) }}</a></div></header>
+<div class="app-shell" wire:poll.30s x-data="{navOpen:false}"><x-sidebar :business="$business" :mode="$section"/><main class="main"><header class="topbar"><div class="heading-row"><button class="mobile-only icon-btn" @click="navOpen=true" aria-label="Open navigation"><x-g-icon name="bars-3"/></button><div><span class="eyebrow">YOUR RESTAURANT, AT A GLANCE</span><h1>{{ $section==='overview'?'Good '.(now()->hour<12?'morning':(now()->hour<18?'afternoon':'evening')).', '.explode(' ',auth()->user()->name)[0]:['orders'=>'Orders & cashier sales','menu'=>'Made for your menu','toppings'=>'Toppings','inventory'=>'Keep the good stuff stocked','kiosks'=>'Your kiosks, together','staff'=>'A team that clicks','reports'=>'The bigger picture'][$section] }}<span class="brand-dot">.</span></h1><p class="muted">{{ $section==='overview'?"Here’s what’s happening at ".$business->name.' today.':'One workspace. Everything in its place.' }}</p></div></div><div class="topbar-actions">@if($section !== 'orders')<div class="branch-select"><x-g-icon name="building-storefront"/><select wire:model.live="selectedKiosk" aria-label="Filter by kiosk"><option value="0">All kiosks</option>@foreach($kiosks as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach</select></div>@endif<a href="{{ route('home') }}" class="avatar" title="Switch business">{{ substr(auth()->user()->name,0,1) }}</a></div></header>
 @if(session('success'))<div class="success-banner gd-success" role="status"><x-g-icon name="check-circle"/>{{ session('success') }}</div>@endif
@@ -14,2 +14,4 @@
 @include('orders.cashier-sales', ['report' => $orderReport])
+@elseif($section==='toppings')
+@include('livewire.partials.toppings-admin')
 @elseif($section==='menu')@php($canUseMenuAi = \App\Services\GatherAi::canManage(auth()->user(), $business->id))<div class="section-line"><div class="search compact"><x-g-icon name="magnifying-glass"/><input wire:model.live.debounce.200ms="search" placeholder="Search menu" aria-label="Search menu"></div><button class="btn primary" wire:click="openForm('product')"><x-g-icon name="plus"/>Add menu item</button></div><div class="product-grid admin-products">@foreach($products as $p)<article class="product-card"><div class="product-photo"><img src="{{ $p->image }}" alt="{{ $p->name }}"><span class="stock-tag">{{ $p->active?'On the menu':'Hidden' }}</span></div><div class="product-info"><span class="product-category">{{ $p->category }}</span><h3>{{ $p->name }}</h3><p class="muted">{{ $p->description }}</p><div class="product-bottom"><strong>₱{{ number_format($p->price/100,2) }}</strong><button class="btn small" wire:click="openForm('product',{{ $p->id }})">Edit item</button>@if($canUseMenuAi)<a class="btn small" href="{{ route('business.ai', ['business' => $business, 'productId' => $p->id]) }}#menu-writing">Write with AI</a>@endif</div></div></article>@endforeach</div>
diff --git a/resources/views/tracking.blade.php b/resources/views/tracking.blade.php
index 24d7ca3..6657d4a 100644
--- a/resources/views/tracking.blade.php
+++ b/resources/views/tracking.blade.php
@@ -1 +1,3 @@
-@extends('layouts.app')@section('content')<main class="customer-app tracking"><header class="customer-header"><x-brand/><span class="table-tag">{{ $order->table?->name??'Takeaway' }}</span></header><span class="success-icon"><x-g-icon name="check"/></span><span class="eyebrow">THANKS FOR ORDERING</span><h1>Good things<br>are on the way<span class="brand-dot">.</span></h1><p class="muted">{{ $order->kiosk->business->name }} · {{ $order->kiosk->name }}</p><section class="card tracking-card"><div class="section-line"><h2>Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }}</h2><span class="badge {{ $order->payment_status }}">{{ ucfirst($order->payment_status) }}</span></div><div class="tracking-steps">@foreach(['received'=>'Received','preparing'=>'Preparing','ready'=>'Ready','completed'=>'Served'] as $s=>$label)<div class="{{ array_search($s,['received','preparing','ready','completed'])<=array_search($order->status,['received','preparing','ready','completed'])?'done':'' }}"><span>{{ $loop->iteration }}</span><strong>{{ $label }}</strong></div>@endforeach</div><p class="muted">{{ ['received'=>'Your order has reached our kitchen.','preparing'=>'We’re making something delicious.','ready'=>'Your order is ready. Please see our team.','completed'=>'Enjoy your meal. We hope to see you again.'][$order->status] }}</p><button class="btn wide" onclick="location.reload()">Refresh status <x-g-icon name="arrow-path"/></button></section><section class="card tracking-card"><h2>Your order</h2>@foreach($order->items as $i)<div class="total-row"><span>{{ $i->quantity }} × {{ $i->name }}</span><strong>₱{{ number_format($i->total/100,2) }}</strong></div>@endforeach<div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div>@if($order->payment_status==='unpaid')<p class="ticket-note">Please pay at the counter. Show your order number to the cashier.</p>@endif</section>@if($order->table)<a class="btn wide" href="{{ route('menu',$order->table->token) }}">Back to menu</a>@endif<button class="btn wide print-btn" onclick="window.print()">Print receipt <x-g-icon name="printer"/></button></main>@endsection
\ No newline at end of file
+@extends('layouts.app')@section('content')<main class="customer-app tracking"><header class="customer-header"><x-brand/><span class="table-tag">{{ $order->table?->name??'Takeaway' }}</span></header><span class="success-icon"><x-g-icon name="check"/></span><span class="eyebrow">THANKS FOR ORDERING</span><h1>Good things<br>are on the way<span class="brand-dot">.</span></h1><p class="muted">{{ $order->kiosk->business->name }} · {{ $order->kiosk->name }}</p><section class="card tracking-card"><div class="section-line"><h2>Order #{{ str_pad($order->id,4,'0',STR_PAD_LEFT) }}</h2><span class="badge {{ $order->payment_status }}">{{ ucfirst($order->payment_status) }}</span></div><div class="tracking-steps">@foreach(['received'=>'Received','preparing'=>'Preparing','ready'=>'Ready','completed'=>'Served'] as $s=>$label)<div class="{{ array_search($s,['received','preparing','ready','completed'])<=array_search($order->status,['received','preparing','ready','completed'])?'done':'' }}"><span>{{ $loop->iteration }}</span><strong>{{ $label }}</strong></div>@endforeach</div><p class="muted">{{ ['received'=>'Your order has reached our kitchen.','preparing'=>'We’re making something delicious.','ready'=>'Your order is ready. Please see our team.','completed'=>'Enjoy your meal. We hope to see you again.'][$order->status] }}</p><button class="btn wide" onclick="location.reload()">Refresh status <x-g-icon name="arrow-path"/></button></section><section class="card tracking-card"><h2>Your order</h2>@foreach($order->items as $i)<div class="total-row"><span>{{ $i->quantity }} × {{ $i->name }}</span><strong>₱{{ number_format($i->total/100,2) }}</strong></div>@endforeach
+@include('livewire.partials.saved-topping-lines')
+<div class="total-row"><span>Service charge</span><span>₱{{ number_format($order->service_charge/100,2) }}</span></div><div class="total-row grand-total"><strong>Total</strong><strong>₱{{ number_format($order->total/100,2) }}</strong></div>@if($order->payment_status==='unpaid')<p class="ticket-note">Please pay at the counter. Show your order number to the cashier.</p>@endif</section>@if($order->table)<a class="btn wide" href="{{ route('menu',$order->table->token) }}">Back to menu</a>@endif<button class="btn wide print-btn" onclick="window.print()">Print receipt <x-g-icon name="printer"/></button></main>@endsection
\ No newline at end of file
ORDERLANE_PATCH
if git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
    echo "Optional toppings are already installed."
    exit 0
fi
git apply --check "$patch_file"
if [[ "${1:-}" == "--check" ]]; then
    echo "Compatibility check passed. No files changed."
    exit 0
fi
git apply "$patch_file"
echo "Optional toppings installed. Run php artisan migrate and php artisan view:clear."
