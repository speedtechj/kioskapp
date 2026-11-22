<?php

use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashierPwaController;
use App\Http\Controllers\SlugLinksController;
use App\Http\Controllers\StaffPinController;
use App\Http\Middleware\CanonicalOrderSlug;
use App\Http\Middleware\CanonicalSlugUrl;
use App\Http\Middleware\PrivateCustomerPage;
use App\Livewire\AiSettings;
use App\Livewire\BusinessSettings;
use App\Livewire\CustomerMenu;
use App\Livewire\CustomerOrder;
use App\Livewire\Kitchen;
use App\Livewire\PaymentDesk;
use App\Livewire\TakeoutMenu;
use App\Livewire\Terminal;
use App\Livewire\Workspace;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/home' : '/login'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware('signed:relative')->scopeBindings()->group(function () {
    Route::get('/cashier/{business}/{kiosk}', [StaffPinController::class, 'signIn'])->middleware('throttle:60,1')->name('cashier.sign-in');
    Route::post('/cashier/{business}/{kiosk}', [StaffPinController::class, 'authenticate'])->middleware(['guest', 'throttle:20,1']);
});
Route::get('/cashier/{business}/{kiosk}/manifest.webmanifest', [CashierPwaController::class, 'manifest'])
    ->scopeBindings()->middleware('throttle:120,1')->name('cashier.manifest');
Route::middleware('auth')->scopeBindings()->group(function () {
    Route::put('/business/{business}/kiosk/{kiosk}/cashier-branding', [CashierPwaController::class, 'save'])
        ->name('cashier.branding.save');
    Route::get('/business/{business}/kiosk/{kiosk}/cashier-access', [StaffPinController::class, 'manage'])->name('cashier.access');
    Route::post('/business/{business}/kiosk/{kiosk}/cashier-access', [StaffPinController::class, 'resetPin'])->middleware('throttle:15,1')->name('cashier.pin.reset');
    Route::get('/business/{business}/kiosk/{kiosk}/change-pin', [StaffPinController::class, 'edit'])->name('cashier.pin.edit');
    Route::post('/business/{business}/kiosk/{kiosk}/change-pin', [StaffPinController::class, 'update'])->middleware('throttle:15,1')->name('cashier.pin.update');
});
Route::middleware('auth')->group(function () {
    Route::get('/home', [AuthController::class, 'home'])->name('home');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    foreach (['/business/{businessId}/admin' => 'workspace', '/business/{businessId}/kiosk/{kioskId}/pos' => 'pos', '/business/{businessId}/kiosk/{kioskId}/kitchen' => 'kitchen', '/business/{businessId}/kiosk/{kioskId}/order/{orderId}/payments' => 'payments', '/qr/{tableId}' => 'qr', '/takeout-qr/{kioskId}' => 'takeout.qr'] as $path => $name) {
        Route::get($path, [SlugLinksController::class, 'legacy'])->whereNumber(['businessId', 'kioskId', 'orderId', 'tableId'])->defaults('destination', $name);
    }
    Route::middleware(CanonicalSlugUrl::class)->scopeBindings()->group(function () {
        Route::get('/business/{business}/promos', [\App\Http\Controllers\MenuPromotionController::class, 'edit'])->name('promotions.edit');
        Route::put('/business/{business}/promos', [\App\Http\Controllers\MenuPromotionController::class, 'save'])->name('promotions.save');
        Route::get('/business/{business}/admin', Workspace::class)->name('workspace');
        Route::get('/business/{business}/reports/sales', \App\Http\Controllers\SalesSummaryController::class)->name('reports.sales');
        Route::get('/business/{business}/audit', \App\Http\Controllers\AuditTrailController::class)->name('audit.trail');
        Route::get('/business/{business}/settings', BusinessSettings::class)->name('business.settings');
        Route::get('/business/{business}/settings/ai', AiSettings::class)->name('business.ai');
        Route::post('/business/{business}/settings/ai/providers/{provider}', AiProviderController::class)
            ->middleware('throttle:15,1')->name('business.ai.provider');
        Route::get('/business/{business}/kiosk/{kiosk}/pos', Terminal::class)->name('pos');
        Route::get('/business/{business}/kiosk/{kiosk}/kitchen', Kitchen::class)->name('kitchen');
        Route::get('/business/{business}/kiosk/{kiosk}/order/{order}/payments', PaymentDesk::class)->name('payments');
        Route::get('/business/{business}/kiosk/{kiosk}/table/{table}/qr', [SlugLinksController::class, 'qr'])->name('qr');
        Route::get('/business/{business}/kiosk/{kiosk}/takeout-qr', [SlugLinksController::class, 'takeoutQr'])->name('takeout.qr');
    });
});
foreach (['/menu/{token}' => 'menu', '/menu/{token}/manifest.webmanifest' => 'menu.manifest', '/takeout/{token}' => 'takeout', '/takeout/{token}/manifest.webmanifest' => 'takeout.manifest'] as $path => $name) {
    Route::get($path, [SlugLinksController::class, 'legacy'])->whereUuid('token')->defaults('destination', $name)->middleware('throttle:120,1');
}
Route::middleware(['throttle:120,1', CanonicalSlugUrl::class])->scopeBindings()->group(function () {
    Route::get('/menu/{business}/{kiosk}/{table}', CustomerMenu::class)->name('menu');
    Route::get('/menu/{business}/{kiosk}/{table}/manifest.webmanifest', [SlugLinksController::class, 'menuManifest'])->name('menu.manifest');
    Route::get('/takeout/{business}/{kiosk}', TakeoutMenu::class)->name('takeout')->middleware(PrivateCustomerPage::class);
    Route::get('/takeout/{business}/{kiosk}/manifest.webmanifest', [SlugLinksController::class, 'takeoutManifest'])->name('takeout.manifest');
});
Route::get('/order/{token}', CustomerOrder::class)->name('tracking')->middleware(['throttle:120,1', PrivateCustomerPage::class, CanonicalOrderSlug::class]);

Route::middleware('auth')->scopeBindings()->group(function () {
    Route::get('/business/{business}/kiosk/{kiosk}/branding', [\App\Http\Controllers\KioskBrandController::class, 'show'])->name('kiosk.brand');
    Route::put('/business/{business}/kiosk/{kiosk}/branding', [\App\Http\Controllers\KioskBrandController::class, 'save'])->name('kiosk.brand.save');
});
