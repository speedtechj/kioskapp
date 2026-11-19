#!/usr/bin/env bash
set -euo pipefail

if [[ $# -gt 1 || ( $# -eq 1 && ${1} != --check ) ]]; then
    echo 'Usage: bash gather-business-availability.sh [--check]' >&2
    exit 2
fi

if [[ ! -f artisan || ! -f app/Filament/Pages/Businesses.php || ! -f app/Services/BusinessAudit.php || ! -f app/Models/RegisterShift.php ]]; then
    echo 'Run this from kioskapp with Business Control, Audit Trail, and cashier shifts installed.' >&2
    exit 1
fi

patch_file=$(mktemp)
trap 'rm -f "$patch_file"' EXIT
cat > "$patch_file" <<'GATHER_AVAILABILITY_PATCH'
diff --git a/app/Filament/Pages/Businesses.php b/app/Filament/Pages/Businesses.php
--- a/app/Filament/Pages/Businesses.php
+++ b/app/Filament/Pages/Businesses.php
@@ -171,4 +171,57 @@
         return $this->selectedBusiness()?->users()->wherePivot('role', 'owner')->orderBy('users.name')->get()
             ?? collect();
+    }
+
+    public function changeAvailabilityAction(): Action
+    {
+        return Action::make('changeAvailability')
+            ->label('Change status')
+            ->color('gray')
+            ->outlined()
+            ->size('sm')
+            ->modalHeading('Change business availability')
+            ->extraModalWindowAttributes(['class' => 'availability-modal'])
+            ->modalDescription(function (array $arguments): string {
+                $business = $this->availabilityBusiness($arguments);
+                $orders = Order::where('business_id', $business->id)->whereNotIn('status', ['completed', 'cancelled'])->count();
+                $shifts = \App\Models\RegisterShift::whereIn('kiosk_id', $business->kiosks()->select('id'))->whereNull('closed_at')->count();
+
+                return $business->name.' · '.$orders.' open '.Str::plural('order', $orders).' · '.$shifts.' active cashier '.Str::plural('shift', $shifts)
+                    .'. Coming Soon and Maintenance block customer and staff access. Existing orders and shifts are kept.';
+            })
+            ->modalSubmitActionLabel('Save status')
+            ->fillForm(fn (array $arguments): array => ['availability' => $this->availabilityBusiness($arguments)->availability])
+            ->schema([
+                \Filament\Forms\Components\Radio::make('availability')
+                    ->label('Select availability')
+                    ->options(\App\Services\BusinessAvailability::LABELS)
+                    ->in(array_keys(\App\Services\BusinessAvailability::LABELS))
+                    ->required(),
+            ])
+            ->action(function (array $data, array $arguments): void {
+                $business = $this->availabilityBusiness($arguments);
+                $next = $data['availability'];
+                DB::transaction(function () use ($business, $next): void {
+                    $locked = Business::lockForUpdate()->findOrFail($business->id);
+                    $previous = $locked->availability;
+                    if ($previous === $next) {
+                        return;
+                    }
+                    $locked->forceFill(['availability' => $next])->save();
+                    \App\Services\BusinessAudit::record($locked->id, 'Business availability changed', $locked->name, details: [
+                        'Before' => \App\Services\BusinessAvailability::LABELS[$previous],
+                        'After' => \App\Services\BusinessAvailability::LABELS[$next],
+                    ]);
+                }, 3);
+
+                Notification::make()->title('Business availability saved')->success()->send();
+            });
+    }
+
+    private function availabilityBusiness(array $arguments): Business
+    {
+        abort_unless(static::canAccess(), 403);
+
+        return Business::findOrFail((int) ($arguments['business'] ?? 0));
     }
 
diff --git a/app/Livewire/AiSettings.php b/app/Livewire/AiSettings.php
--- a/app/Livewire/AiSettings.php
+++ b/app/Livewire/AiSettings.php
@@ -19,4 +19,6 @@
 class AiSettings extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public int $businessId;
diff --git a/app/Livewire/BusinessSettings.php b/app/Livewire/BusinessSettings.php
--- a/app/Livewire/BusinessSettings.php
+++ b/app/Livewire/BusinessSettings.php
@@ -12,4 +12,6 @@
 class BusinessSettings extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public int $businessId;
diff --git a/app/Livewire/CustomerMenu.php b/app/Livewire/CustomerMenu.php
--- a/app/Livewire/CustomerMenu.php
+++ b/app/Livewire/CustomerMenu.php
@@ -13,4 +13,6 @@
 class CustomerMenu extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public string $token;
diff --git a/app/Livewire/CustomerOrder.php b/app/Livewire/CustomerOrder.php
--- a/app/Livewire/CustomerOrder.php
+++ b/app/Livewire/CustomerOrder.php
@@ -10,4 +10,6 @@
 class CustomerOrder extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public string $token;
diff --git a/app/Livewire/Kitchen.php b/app/Livewire/Kitchen.php
--- a/app/Livewire/Kitchen.php
+++ b/app/Livewire/Kitchen.php
@@ -15,4 +15,6 @@
 class Kitchen extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public int $businessId;
@@ -50,4 +52,5 @@
         if ($next) {
             DB::transaction(function () use ($order, $next) {
+                \App\Services\BusinessAvailability::enforce($order->business_id, lock: true);
                 $transition = Order::whereKey($order->id)->where('status', $order->status);
                 $requiresPayment = $next === 'completed' && $order->dining_table_id === null;
diff --git a/app/Livewire/PaymentDesk.php b/app/Livewire/PaymentDesk.php
--- a/app/Livewire/PaymentDesk.php
+++ b/app/Livewire/PaymentDesk.php
@@ -22,4 +22,6 @@
 class PaymentDesk extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     use ApprovesOrderAdjustments;
     use ManagesRegisterShift;
@@ -127,4 +129,5 @@
         $order = $this->order();
         $order = DB::transaction(function () use ($order, $proposal, $approval) {
+            \App\Services\BusinessAvailability::enforce($order->business_id, lock: true);
             Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
             RegisterShifts::requireOpen($order->kiosk_id);
diff --git a/app/Livewire/TakeoutMenu.php b/app/Livewire/TakeoutMenu.php
--- a/app/Livewire/TakeoutMenu.php
+++ b/app/Livewire/TakeoutMenu.php
@@ -12,4 +12,6 @@
 class TakeoutMenu extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     #[Locked]
     public string $token;
diff --git a/app/Livewire/Terminal.php b/app/Livewire/Terminal.php
--- a/app/Livewire/Terminal.php
+++ b/app/Livewire/Terminal.php
@@ -20,4 +20,6 @@
 class Terminal extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     use ApprovesOrderAdjustments;
     use ManagesRegisterShift;
diff --git a/app/Livewire/Workspace.php b/app/Livewire/Workspace.php
--- a/app/Livewire/Workspace.php
+++ b/app/Livewire/Workspace.php
@@ -26,4 +26,6 @@
 class Workspace extends Component
 {
+    use \App\Livewire\Concerns\ChecksBusinessAvailability;
+
     // gather-menu-photo-upload:v1
     use WithFileUploads;
diff --git a/app/Services/CancelOrder.php b/app/Services/CancelOrder.php
--- a/app/Services/CancelOrder.php
+++ b/app/Services/CancelOrder.php
@@ -26,4 +26,5 @@
     {
         DB::transaction(function () use ($order, $customer) {
+            BusinessAvailability::enforce($order->business_id, lock: true);
             Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
             $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
@@ -62,4 +63,5 @@
         Access::authorize($order->business_id, 'orders.pay', $order->kiosk_id);
         DB::transaction(function () use ($order, $paymentId) {
+            BusinessAvailability::enforce($order->business_id, lock: true);
             Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
             $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
diff --git a/app/Services/PlaceOrder.php b/app/Services/PlaceOrder.php
--- a/app/Services/PlaceOrder.php
+++ b/app/Services/PlaceOrder.php
@@ -50,4 +50,5 @@
 
         return DB::transaction(function () use ($kiosk, $cart, $requestKey, $table, $method, $name, $notes, $payments, $isPos, $payAtPickup, $cashReceived, $complimentaryIds, $discountType, $discountValue, $adjustmentApprovals, $approvedCart) {
+            BusinessAvailability::enforce($kiosk->business_id, lock: true);
             $locked = Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
             abort_unless($locked->active, 404);
diff --git a/app/Services/RecordPayment.php b/app/Services/RecordPayment.php
--- a/app/Services/RecordPayment.php
+++ b/app/Services/RecordPayment.php
@@ -51,4 +51,5 @@
 
         return DB::transaction(function () use ($order, $payments, $batchKey) {
+            BusinessAvailability::enforce($order->business_id, lock: true);
             Kiosk::whereKey($order->kiosk_id)->lockForUpdate()->firstOrFail();
             $locked = Order::whereKey($order->id)->where('business_id', $order->business_id)->where('kiosk_id', $order->kiosk_id)->lockForUpdate()->firstOrFail();
diff --git a/app/Services/RegisterShifts.php b/app/Services/RegisterShifts.php
--- a/app/Services/RegisterShifts.php
+++ b/app/Services/RegisterShifts.php
@@ -33,4 +33,5 @@
         Access::authorize($kiosk->business_id, 'orders.pay', $kiosk->id);
         DB::transaction(function () use ($kiosk, $cash) {
+            BusinessAvailability::enforce($kiosk->business_id, lock: true);
             $locked = Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
             abort_unless($locked->active, 404);
@@ -54,4 +55,5 @@
         Access::authorize($kiosk->business_id, 'orders.pay', $kiosk->id);
         DB::transaction(function () use ($kiosk, $shiftId, $counts, $reviewedExpected) {
+            BusinessAvailability::enforce($kiosk->business_id, lock: true);
             Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
             $shift = self::active($kiosk->id);
diff --git a/bootstrap/app.php b/bootstrap/app.php
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -13,5 +13,6 @@
     )
     ->withMiddleware(function (Middleware $middleware): void {
-        $middleware->web(append: [\App\Http\Middleware\LimitPinSession::class]);
+        $middleware->web(append: [\App\Http\Middleware\LimitPinSession::class, \App\Http\Middleware\EnsureBusinessAvailable::class]);
+        $middleware->api(append: [\App\Http\Middleware\EnsureBusinessAvailable::class]);
     })
     ->withExceptions(function (Exceptions $exceptions): void {
diff --git a/resources/views/filament/pages/businesses.blade.php b/resources/views/filament/pages/businesses.blade.php
--- a/resources/views/filament/pages/businesses.blade.php
+++ b/resources/views/filament/pages/businesses.blade.php
@@ -1,3 +1,4 @@
 <x-filament-panels::page>
+    <link rel="stylesheet" href="{{ asset('branding/business-availability.css') }}">
     @php
         $selected = $this->selectedBusiness();
@@ -32,14 +33,16 @@
                     <div class="control-form">
                         <x-control-input field="search" label="Search by business name or slug" :live="true" :required="false"/>
-                        <div class="control-scroll"><table class="control-table">
-                            <thead><tr><th>Business</th><th>Slug</th><th>Kiosks</th></tr></thead>
+                        <div class="control-scroll"><table class="control-table availability-table">
+                            <thead><tr><th>Business</th><th>Slug</th><th>Kiosks</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
                             <tbody>
                             @forelse($this->getBusinesses() as $business)
                                 <tr wire:key="business-{{ $business->id }}" class="{{ $selectedId === $business->id ? 'control-selected' : '' }}">
                                     <td><button type="button" wire:click="selectBusiness({{ $business->id }})" aria-pressed="{{ $selectedId === $business->id ? 'true' : 'false' }}" class="control-brand"><img src="{{ $business->logoUrl() ?? $defaultLogo }}" alt=""><strong>{{ $business->name }}</strong></button></td>
-                                    <td>{{ $business->slug }}</td><td>{{ $business->kiosks_count }}</td>
+                                    <td class="availability-extra">{{ $business->slug }}</td><td class="availability-extra">{{ $business->kiosks_count }}</td>
+                                    <td><span @class(['availability-badge', 'availability-'.$business->availability])>{{ \App\Services\BusinessAvailability::LABELS[$business->availability] }}</span></td>
+                                    <td>{{ ($this->changeAvailabilityAction)(['business' => $business->id]) }}</td>
                                 </tr>
                             @empty
-                                <tr><td colspan="3">No matching businesses.</td></tr>
+                                <tr><td colspan="5">No matching businesses.</td></tr>
                             @endforelse
                             </tbody>
diff --git a/resources/views/layouts/app.blade.php b/resources/views/layouts/app.blade.php
--- a/resources/views/layouts/app.blade.php
+++ b/resources/views/layouts/app.blade.php
@@ -31,4 +31,16 @@
 @endif
 @if(!empty($aiSettings) || $brandKiosk)<link rel="stylesheet" href="{{ asset('branding/ai-settings.css') }}">@endif
+<script>
+    document.addEventListener('livewire:init', () => {
+        Livewire.interceptRequest(({ onError }) => {
+            onError(({ response, preventDefault }) => {
+                if (response.headers.get('X-Gather-Business-Paused') === '1') {
+                    preventDefault();
+                    window.location.reload();
+                }
+            });
+        });
+    });
+</script>
 </head><body class="gather-type">{{ $slot ?? '' }}@yield('content')@livewireScripts<x-confirm-dialog/><script defer src="{{ asset('gather-dialogs-v1.js') }}"></script>
 @if($brandKiosk)
diff --git a/app/Services/BusinessAvailability.php b/app/Services/BusinessAvailability.php
new file mode 100644
--- /dev/null
+++ b/app/Services/BusinessAvailability.php
@@ -0,0 +1,73 @@
+<?php
+
+namespace App\Services;
+
+use App\Models\Business;
+use App\Models\DiningTable;
+use App\Models\Kiosk;
+use App\Models\Order;
+use Illuminate\Http\Exceptions\HttpResponseException;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+class BusinessAvailability
+{
+    public const LABELS = [
+        'live' => 'Live',
+        'coming_soon' => 'Coming Soon',
+        'maintenance' => 'Maintenance',
+    ];
+
+    public static function customerBusinessId(string $surface, string $token): ?int
+    {
+        return match ($surface) {
+            'menu' => DiningTable::where('token', $token)->first()?->kiosk?->business_id,
+            'takeout' => Kiosk::where('takeout_token', $token)->value('business_id'),
+            'tracking' => Order::where(fn ($query) => $query->where('slug', $token)->orWhere('token', $token))->value('business_id'),
+        };
+    }
+
+    public static function enforce(int $businessId, ?string $surface = null, bool $lock = false): void
+    {
+        $business = Business::query()->when($lock, fn ($query) => $query->sharedLock())->findOrFail($businessId);
+        if (self::paused($business)) {
+            throw new HttpResponseException(self::response($business, $surface, request()));
+        }
+    }
+
+    public static function paused(Business $business): bool
+    {
+        return in_array($business->availability, ['coming_soon', 'maintenance'], true);
+    }
+
+    public static function response(Business $business, ?string $surface, Request $request): Response
+    {
+        $staffTitle = match ($surface) {
+            'pos', 'cashier.sign-in', 'cashier.manifest' => 'Point of Sale paused',
+            'kitchen' => 'Kitchen display paused',
+            'payments' => 'Payments paused',
+            'workspace' => 'Workspace paused',
+            default => null,
+        };
+        $comingSoon = $business->availability === 'coming_soon';
+        $title = $staffTitle ?? ($comingSoon ? 'We’re opening soon' : 'Temporarily unavailable');
+        $message = match (true) {
+            $staffTitle !== null => $comingSoon ? 'This business isn’t live yet.' : 'This business is under maintenance.',
+            $comingSoon => 'This business hasn’t opened yet. Please check back later.',
+            default => 'We’re doing some maintenance. Please check back shortly.',
+        };
+        $headers = [
+            'Cache-Control' => 'private, no-store',
+            'Referrer-Policy' => 'no-referrer',
+            'X-Robots-Tag' => 'noindex',
+            'X-Gather-Business-Paused' => '1',
+        ];
+        $retry = ! $comingSoon && $request->isMethodSafe();
+
+        if ($request->expectsJson() || $request->is('api/*')) {
+            return response()->json(['message' => $message, 'availability' => $business->availability], 503, $headers);
+        }
+
+        return response()->view('status.business-availability', compact('business', 'comingSoon', 'title', 'message', 'retry'), 503, $headers);
+    }
+}
diff --git a/app/Http/Middleware/EnsureBusinessAvailable.php b/app/Http/Middleware/EnsureBusinessAvailable.php
new file mode 100644
--- /dev/null
+++ b/app/Http/Middleware/EnsureBusinessAvailable.php
@@ -0,0 +1,33 @@
+<?php
+
+namespace App\Http\Middleware;
+
+use App\Models\Business;
+use App\Services\BusinessAvailability;
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+class EnsureBusinessAvailable
+{
+    public function handle(Request $request, Closure $next): Response
+    {
+        $surface = $request->route()?->getName() ?? $request->route()?->defaults['destination'] ?? null;
+        $business = $request->route('business');
+
+        if (! $business instanceof Business && $request->route('token') && in_array($surface, ['menu', 'menu.manifest', 'takeout', 'takeout.manifest', 'tracking'], true)) {
+            $id = BusinessAvailability::customerBusinessId(explode('.', $surface)[0], $request->route('token'));
+            $business = $id ? Business::find($id) : null;
+        }
+
+        if ($business instanceof Business && BusinessAvailability::paused($business)) {
+            if ($request->is('business/*') && ! in_array($surface, ['pos', 'kitchen', 'payments'], true)) {
+                $surface = 'workspace';
+            }
+
+            return BusinessAvailability::response($business, $surface, $request);
+        }
+
+        return $next($request);
+    }
+}
diff --git a/app/Livewire/Concerns/ChecksBusinessAvailability.php b/app/Livewire/Concerns/ChecksBusinessAvailability.php
new file mode 100644
--- /dev/null
+++ b/app/Livewire/Concerns/ChecksBusinessAvailability.php
@@ -0,0 +1,34 @@
+<?php
+
+namespace App\Livewire\Concerns;
+
+use App\Livewire\CustomerMenu;
+use App\Livewire\CustomerOrder;
+use App\Livewire\Kitchen;
+use App\Livewire\PaymentDesk;
+use App\Livewire\TakeoutMenu;
+use App\Livewire\Terminal;
+use App\Services\BusinessAvailability;
+
+trait ChecksBusinessAvailability
+{
+    public function hydrateChecksBusinessAvailability(): void
+    {
+        $surface = match (true) {
+            $this instanceof CustomerMenu => 'menu',
+            $this instanceof TakeoutMenu => 'takeout',
+            $this instanceof CustomerOrder => 'tracking',
+            $this instanceof Terminal => 'pos',
+            $this instanceof Kitchen => 'kitchen',
+            $this instanceof PaymentDesk => 'payments',
+            default => 'workspace',
+        };
+        $id = match ($surface) {
+            'menu', 'takeout', 'tracking' => BusinessAvailability::customerBusinessId($surface, $this->token),
+            default => $this->businessId,
+        };
+
+        abort_if($id === null, 404);
+        BusinessAvailability::enforce($id, $surface);
+    }
+}
diff --git a/database/migrations/2026_11_28_000001_add_availability_to_businesses.php b/database/migrations/2026_11_28_000001_add_availability_to_businesses.php
new file mode 100644
--- /dev/null
+++ b/database/migrations/2026_11_28_000001_add_availability_to_businesses.php
@@ -0,0 +1,22 @@
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
+        Schema::table('businesses', function (Blueprint $table) {
+            $table->string('availability', 24)->default('live');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('businesses', function (Blueprint $table) {
+            $table->dropColumn('availability');
+        });
+    }
+};
diff --git a/resources/views/status/business-availability.blade.php b/resources/views/status/business-availability.blade.php
new file mode 100644
--- /dev/null
+++ b/resources/views/status/business-availability.blade.php
@@ -0,0 +1,30 @@
+<!doctype html>
+<html lang="en">
+<head>
+    <meta charset="utf-8">
+    <meta name="viewport" content="width=device-width, initial-scale=1">
+    <meta name="robots" content="noindex">
+    <title>{{ $title }} · {{ $business->name }}</title>
+    <link rel="stylesheet" href="{{ asset('branding/business-availability.css') }}">
+</head>
+<body class="availability-page">
+    <main class="availability-card">
+        <div class="availability-brand">
+            @if($logo = $business->logoUrl())
+                <img src="{{ $logo }}" width="48" height="48" alt="">
+            @else
+                <img src="{{ asset('branding/bowl-192.png') }}" width="48" height="48" alt="">
+            @endif
+            <span>{{ $business->name }}</span>
+        </div>
+        <div class="availability-symbol">
+            <x-g-icon :name="$comingSoon ? 'building-storefront' : 'wrench-screwdriver'"/>
+        </div>
+        <h1>{{ $title }}</h1>
+        <p>{{ $message }}</p>
+        @if($retry)
+            <a class="availability-retry" href="{{ request()->fullUrl() }}">Try again</a>
+        @endif
+    </main>
+</body>
+</html>
diff --git a/public/branding/business-availability.css b/public/branding/business-availability.css
new file mode 100644
--- /dev/null
+++ b/public/branding/business-availability.css
@@ -0,0 +1,97 @@
+.availability-page {
+    box-sizing: border-box;
+    min-height: 100vh;
+    min-height: 100dvh;
+    margin: 0;
+    padding: 24px 16px;
+    display: grid;
+    place-items: center;
+    background: #f7f3ea;
+    color: #173025;
+    font: 1rem/1.55 Inter, system-ui, sans-serif;
+}
+.availability-card {
+    box-sizing: border-box;
+    width: min(100%, 600px);
+    padding: clamp(24px, 6vw, 52px);
+    border: 1px solid #e7ded0;
+    border-radius: 18px;
+    background: #fffdfa;
+    box-shadow: 0 8px 28px #30271b12;
+    text-align: center;
+    overflow-wrap: anywhere;
+}
+.availability-brand {
+    display: flex;
+    gap: 12px;
+    align-items: center;
+    justify-content: center;
+    font: 700 clamp(1.15rem, 4vw, 1.55rem)/1.2 Georgia, serif;
+}
+.availability-brand img { flex: 0 0 auto; object-fit: contain; border-radius: 9px; }
+.availability-symbol {
+    display: grid;
+    width: 76px;
+    height: 76px;
+    margin: 28px auto 18px;
+    place-items: center;
+    color: #ad4516;
+    background: #fce8d8;
+    border-radius: 50%;
+}
+.availability-symbol svg { display: block; width: 38px; height: 38px; }
+.availability-card h1 { margin: 0; font: 700 clamp(1.9rem, 6vw, 2.8rem)/1.12 Georgia, serif; }
+.availability-card p { margin: 12px auto 0; max-width: 34ch; color: #3d493e; }
+.availability-retry {
+    box-sizing: border-box;
+    display: inline-grid;
+    min-height: 44px;
+    margin-top: 22px;
+    padding: 6px 22px;
+    place-items: center;
+    border: 1px solid #ad4516;
+    border-radius: 9px;
+    color: #a43b10;
+    font-weight: 700;
+    text-decoration: none;
+}
+.availability-retry:is(:hover, :focus-visible) { background: #fce8d8; }
+.availability-retry:focus-visible { outline: 2px solid #a43b10; outline-offset: 3px; }
+
+.availability-table .availability-badge {
+    display: inline-block;
+    border-radius: 999px;
+    padding: 4px 9px;
+    white-space: nowrap;
+    font-size: .82rem;
+    font-weight: 650;
+}
+.availability-live { background: #dcefe1; color: #174c2b; }
+.availability-coming_soon { background: #fff0c9; color: #694900; }
+.availability-maintenance { background: #f6e4df; color: #7d321d; }
+@media (max-width: 700px) {
+    .control-page .availability-table,
+    .availability-table tbody { display: block; min-width: 0; }
+    .availability-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
+    .availability-table tbody tr {
+        display: grid;
+        gap: 8px;
+        padding: 12px;
+        margin-bottom: 10px;
+        border: 1px solid #a1978760;
+        border-radius: 10px;
+    }
+    .control-page .availability-table td { display: block; padding: 0; border: 0; white-space: normal; }
+    .control-page .availability-table .availability-extra { display: none; }
+    .availability-table .fi-btn { width: 100%; min-height: 44px; }
+    .fi-modal-window-ctn:has(> .availability-modal) { align-items: end; padding: 0; }
+    .fi-modal-window.availability-modal {
+        width: 100%;
+        max-width: none;
+        max-height: 90dvh;
+        margin: 0;
+        overflow-y: auto;
+        border-radius: 18px 18px 0 0;
+        padding-bottom: env(safe-area-inset-bottom);
+    }
+}
diff --git a/tests/Feature/BusinessAvailabilityTest.php b/tests/Feature/BusinessAvailabilityTest.php
new file mode 100644
--- /dev/null
+++ b/tests/Feature/BusinessAvailabilityTest.php
@@ -0,0 +1,83 @@
+<?php
+
+namespace Tests\Feature;
+
+use App\Filament\Pages\Businesses;
+use App\Livewire\CustomerMenu;
+use App\Livewire\PaymentDesk;
+use App\Models\Business;
+use App\Models\Order;
+use App\Models\User;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Str;
+use Livewire\Livewire;
+use Tests\TestCase;
+
+class BusinessAvailabilityTest extends TestCase
+{
+    use RefreshDatabase;
+
+    public function test_admin_can_pause_and_reopen_only_the_selected_business(): void
+    {
+        $this->withoutVite();
+        $business = Business::create(['name' => 'Test Restaurant']);
+        $other = Business::create(['name' => 'Other Restaurant']);
+        $owner = User::factory()->create();
+        $business->users()->attach($owner, ['role' => 'owner']);
+        $kiosk = $business->kiosks()->create(['name' => 'Main']);
+        $table = $kiosk->tables()->create(['name' => 'Table 1', 'token' => Str::uuid()->toString()]);
+        $menu = route('menu', [$business, $kiosk, $table]);
+
+        $this->actingAs($owner)->get('/control/businesses')->assertForbidden();
+        $admin = User::factory()->create(['is_super_admin' => true]);
+        $this->actingAs($admin)->get('/control/businesses')->assertOk()->assertSee('Change status');
+        Livewire::test(Businesses::class)
+            ->callAction('changeAvailability', data: ['availability' => 'coming_soon'], arguments: ['business' => $business->id])->assertHasNoErrors();
+
+        $this->assertSame('coming_soon', $business->fresh()->availability);
+        $this->assertSame('live', $other->fresh()->availability);
+        $otherKiosk = $other->kiosks()->create(['name' => 'Other kiosk']);
+        $this->get(route('takeout', [$other, $otherKiosk]))->assertOk();
+        $this->assertDatabaseHas('business_audit_events', [
+            'business_id' => $business->id, 'actor_id' => $admin->id, 'action' => 'Business availability changed',
+        ]);
+
+        $this->get($menu)->assertStatus(503)->assertSee('We’re opening soon')->assertDontSee('Try again');
+        $this->get('/menu/'.$table->token)->assertStatus(503);
+        $this->get(route('takeout', [$business, $kiosk]))->assertStatus(503);
+        $this->get(route('workspace', $business))->assertStatus(503)->assertSee('Workspace paused');
+        $this->get(route('pos', [$business, $kiosk]))->assertStatus(503)->assertSee('Point of Sale paused');
+        $this->get(route('kitchen', [$business, $kiosk]))->assertStatus(503)->assertSee('Kitchen display paused');
+
+        Livewire::test(Businesses::class)
+            ->callAction('changeAvailability', data: ['availability' => 'live'], arguments: ['business' => $business->id])->assertHasNoErrors();
+
+        $this->get($menu)->assertOk();
+    }
+
+    public function test_open_livewire_tabs_cannot_submit_orders_or_payments_after_pause(): void
+    {
+        $this->withoutVite();
+        $business = Business::create(['name' => 'Restaurant']);
+        $kiosk = $business->kiosks()->create(['name' => 'Main']);
+        $table = $kiosk->tables()->create(['name' => 'Table 1', 'token' => Str::uuid()->toString()]);
+        $customer = Livewire::test(CustomerMenu::class, compact('business', 'kiosk', 'table'));
+        $owner = User::factory()->create();
+        $business->users()->attach($owner, ['role' => 'owner']);
+        $order = Order::create([
+            'business_id' => $business->id, 'kiosk_id' => $kiosk->id, 'token' => Str::uuid()->toString(),
+            'request_key' => Str::uuid()->toString(), 'source' => 'qr', 'status' => 'received',
+            'subtotal' => 10000, 'total' => 10000,
+        ]);
+        $this->actingAs($owner);
+        $cashier = Livewire::test(PaymentDesk::class, compact('business', 'kiosk', 'order'));
+        $business->forceFill(['availability' => 'maintenance'])->save();
+
+        $customer->call('submit')->assertStatus(503);
+        $cashier->call('record')->assertStatus(503);
+        $this->get(route('payments', [$business, $kiosk, $order]))->assertStatus(503)->assertSee('Payments paused')->assertSee('Try again');
+        $this->getJson(route('tracking', $order->slug))->assertStatus(503)->assertJsonPath('availability', 'maintenance');
+        $this->assertDatabaseCount('orders', 1);
+        $this->assertDatabaseCount('order_payments', 0);
+    }
+}
GATHER_AVAILABILITY_PATCH

if git apply --reverse --check "$patch_file" 2>/dev/null; then
    echo 'Business Availability is already installed.'
    exit 0
fi

if ! git apply --check "$patch_file"; then
    echo 'A Gather source file differs from the expected version. No files changed.' >&2
    exit 1
fi

if [[ ${1:-} == --check ]]; then
    echo 'Business Availability compatibility check passed. No files changed.'
    exit 0
fi

git apply "$patch_file"
echo 'Business Availability installed. Run php artisan migrate and php artisan view:clear.'
