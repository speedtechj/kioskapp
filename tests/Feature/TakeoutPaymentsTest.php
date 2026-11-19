<?php

namespace Tests\Feature;

use App\Livewire\CustomerOrder;
use App\Livewire\PaymentDesk;
use App\Livewire\TakeoutMenu;
use App\Livewire\Terminal;
use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PlaceOrder;
use App\Services\RecordPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TakeoutPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Business $other;

    private Kiosk $kiosk;

    private Kiosk $second;

    private Kiosk $foreign;

    private Product $bowl;

    private Product $latte;

    private Product $secret;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->business = Business::create(['name' => 'Gather Restaurant', 'slug' => 'gather']);
        $this->other = Business::create(['name' => 'Other Cafe', 'slug' => 'other']);
        $this->kiosk = $this->business->kiosks()->create(['name' => 'Main branch']);
        $this->second = $this->business->kiosks()->create(['name' => 'Second branch']);
        $this->foreign = $this->other->kiosks()->create(['name' => 'Foreign branch']);
        $this->bowl = $this->business->products()->create(['name' => 'Chicken Rice Bowl', 'category' => 'Bowls', 'price' => 18900, 'image' => '/images/bowl.jpg']);
        $this->latte = $this->business->products()->create(['name' => 'Iced Latte', 'category' => 'Drinks', 'price' => 12000, 'image' => '/images/latte.jpg']);
        $this->secret = $this->other->products()->create(['name' => 'Other secret item', 'category' => 'Other', 'price' => 9900]);
        foreach ([$this->kiosk, $this->second] as $k) {
            $k->products()->attach($this->bowl, ['stock' => 10]);
            $k->products()->attach($this->latte, ['stock' => 10]);
        }$this->foreign->products()->attach($this->secret, ['stock' => 10]);
        $this->owner = User::factory()->create();
        $this->cashier = User::factory()->create();
        $this->business->users()->attach($this->owner, ['role' => 'owner']);
        $this->business->users()->attach($this->cashier, ['role' => 'cashier']);
        $this->cashier->kiosks()->attach($this->kiosk);
    }

    private function order(?Kiosk $kiosk = null): Order
    {
        return app(PlaceOrder::class)->handle($kiosk ?? $this->kiosk, [$this->bowl->id => 1, $this->latte->id => 1], (string) Str::uuid());
    }

    public function test_guest_can_open_takeout_menu_without_table_or_login(): void
    {
        $this->assertTrue(Str::isUuid($this->kiosk->takeout_token));
        $this->assertNotSame($this->kiosk->takeout_token, $this->second->takeout_token);
        $this->get(route('takeout', [$this->kiosk->business, $this->kiosk]))->assertOk()->assertSee('Good food. To go')->assertSee('Chicken Rice Bowl')->assertDontSee('Other secret item')->assertDontSee('Table 01')->assertDontSee('Sign in');
    }

    public function test_guest_takeout_submit_reserves_stock_and_has_no_table(): void
    {
        Livewire::test(TakeoutMenu::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->bowl->id)->call('add', $this->latte->id)->set('reviewOpen', true)->assertSee('Your takeout order')->set('name', 'Jamie')->call('submit')->assertHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertNull($order->dining_table_id);
        $this->assertNull($order->user_id);
        $this->assertSame(30900, $order->total);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame(9, $this->kiosk->products()->find($this->bowl->id)->pivot->stock);
        $response = $this->get(route('tracking', $order->slug))->assertOk()->assertSee('Collect at the counter')->assertSee('309.00');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_takeout_cart_is_idempotent_when_submit_is_replayed(): void
    {
        $component = Livewire::test(TakeoutMenu::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->bowl->id);
        $component->call('submit')->call('submit');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(9, $this->kiosk->products()->find($this->bowl->id)->pivot->stock);
    }

    public function test_takeout_tokens_and_products_are_scoped(): void
    {
        $this->get('/takeout/not-a-token')->assertNotFound();
        Livewire::test(TakeoutMenu::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->secret->id)->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_paused_kiosk_cannot_accept_takeout_or_serve_manifest(): void
    {
        $component = Livewire::test(TakeoutMenu::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->bowl->id);
        $this->kiosk->update(['active' => false]);
        $component->call('submit')->assertNotFound();
        $this->get(route('takeout.manifest', [$this->kiosk->business, $this->kiosk]))->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_takeout_manifest_is_specific_to_the_kiosk(): void
    {
        $this->get(route('takeout.manifest', [$this->kiosk->business, $this->kiosk]))->assertOk()->assertJsonPath('start_url', route('takeout', [$this->business, $this->kiosk], false));
    }

    public function test_owner_can_get_takeout_qr_but_other_tenants_cannot(): void
    {
        $this->actingAs($this->owner)->get(route('takeout.qr', [$this->kiosk->business, $this->kiosk]))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get(route('takeout.qr', [$this->foreign->business, $this->foreign]))->assertForbidden();
        $this->get(route('workspace', ['business' => $this->business, 'tab' => 'kiosks']))->assertOk()->assertSee('Open takeout app');
    }

    public function test_cashier_can_record_partial_then_finish_a_split_payment(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        $service = app(RecordPayment::class);
        $service->handle($order, [['method' => 'gcash', 'amount' => '200.00', 'reference' => 'STAFF-ONLY-REF']], (string) Str::uuid());
        $this->assertSame('partial', $order->fresh()->payment_status);
        $this->assertSame(20000, $order->fresh()->paidAmount());
        $this->assertSame(10900, $order->fresh()->remainingAmount());
        $service->handle($order, [['method' => 'cash', 'amount' => '109.00']], (string) Str::uuid());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('split', $order->fresh()->payment_method);
        $this->assertSame(30900, $order->fresh()->paidAmount());
        $this->assertSame(0, $order->fresh()->remainingAmount());
        $this->assertDatabaseCount('order_payments', 2);
        $this->get(route('tracking', ['token' => $order->slug, 'view' => 'payment']))->assertOk()->assertSee('GCash')->assertSee('200.00')->assertSee('109.00')->assertSee('Fully paid')->assertDontSee('STAFF-ONLY-REF');
    }

    public function test_payment_batches_are_idempotent(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        $key = (string) Str::uuid();
        $service = app(RecordPayment::class);
        $service->handle($order, [['method' => 'gcash', 'amount' => '200']], $key);
        $service->handle($order, [['method' => 'gcash', 'amount' => '200']], $key);
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame(20000, $order->fresh()->paidAmount());
    }

    public function test_overpayment_is_rejected_atomically_including_stale_order_objects(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        $service = app(RecordPayment::class);
        $service->handle($order, [['method' => 'gcash', 'amount' => '200']], (string) Str::uuid());
        try {
            $service->handle($order, [['method' => 'cash', 'amount' => '100'], ['method' => 'card', 'amount' => '10']], (string) Str::uuid());
            $this->fail('Expected an overpayment error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertSame(20000, $order->fresh()->paidAmount());
    }

    public function test_payment_amounts_reject_negative_zero_and_extra_decimals(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        foreach (['-1.00', '0', '1.001', '1e3', 'NaN'] as $amount) {
            try {
                app(RecordPayment::class)->handle($order, [['method' => 'cash', 'amount' => $amount]], (string) Str::uuid());
                $this->fail('Expected invalid amount.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_payment_arithmetic_uses_exact_cents(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        app(RecordPayment::class)->handle($order, [['method' => 'gcash', 'amount' => '0.10'], ['method' => 'cash', 'amount' => '0.20']], (string) Str::uuid());
        $this->assertSame(30, $order->fresh()->paidAmount());
        $this->assertSame(30870, $order->fresh()->remainingAmount());
    }

    public function test_cashier_cannot_collect_payments_at_unassigned_kiosk(): void
    {
        $order = $this->order($this->second);
        $this->actingAs($this->cashier)->get(route('payments', [$this->business, $this->second, $order]))->assertForbidden();
        $this->get(route('payments', [$this->business, $this->kiosk, $order]))->assertNotFound();
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_customer_cannot_confirm_payment(): void
    {
        $order = $this->order();
        $this->get(route('payments', [$this->business, $this->kiosk, $order]))->assertRedirect('/login');
        try {
            app(RecordPayment::class)->handle($order, [['method' => 'cash', 'amount' => '309']], (string) Str::uuid());
            $this->fail('Expected forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }$this->assertDatabaseCount('order_payments', 0);
    }

    public function test_pos_can_checkout_with_two_confirmed_payment_methods(): void
    {
        $this->actingAs($this->cashier);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->bowl->id)->call('add', $this->latte->id)->set('payment', 'split')->set('paymentRows', [['method' => 'gcash', 'amount' => '200.00'], ['method' => 'cash', 'amount' => '109.00']])->call('checkout')->assertHasNoErrors()->assertSet('cart', []);
        $order = Order::firstOrFail();
        $this->assertSame('pos', $order->source);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('split', $order->payment_method);
        $this->assertSame(30900, $order->paidAmount());
        $this->assertDatabaseCount('order_payments', 2);
    }

    public function test_pos_split_must_match_total_and_rolls_back_stock_on_error(): void
    {
        $this->actingAs($this->cashier);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->bowl->id)->set('payment', 'split')->set('paymentRows', [['method' => 'cash', 'amount' => '10'], ['method' => 'gcash', 'amount' => '10']])->call('checkout')->assertHasErrors(['payments']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertSame(10, $this->kiosk->products()->find($this->bowl->id)->pivot->stock);
    }

    public function test_payment_desk_records_partial_payment_and_protects_context(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        Livewire::test(PaymentDesk::class, ['business' => $this->business, 'kiosk' => $this->kiosk, 'order' => $order])->set('payments', [['method' => 'gcash', 'amount' => '200.00', 'reference' => 'CHECKED']])->call('record')->assertHasNoErrors()->assertSee('109.00');
        $this->assertSame('partial', $order->fresh()->payment_status);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->set('incomingOpen', true)->assertSee('Collect / split payment');
    }

    public function test_legacy_record_cash_only_collects_remaining_balance(): void
    {
        $order = $this->order();
        $this->actingAs($this->cashier);
        app(RecordPayment::class)->handle($order, [['method' => 'gcash', 'amount' => '200']], (string) Str::uuid());
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('markPaid', $order->id)->assertHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(10900, (int) $order->payments()->where('method', 'cash')->sum('amount'));
    }

    public function test_previously_paid_orders_and_existing_kiosks_are_backfilled_without_duplicates(): void
    {
        $order = $this->order();
        $order->update(['payment_status' => 'paid', 'payment_method' => 'cash']);
        DB::table('kiosks')->where('id', $this->kiosk->id)->update(['takeout_token' => null]);
        $migration = require database_path('migrations/2026_11_18_052021_add_takeout_and_split_payments.php');
        $migration->up();
        $migration->up();
        $this->assertNotNull($this->kiosk->fresh()->takeout_token);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(30900, (int) $order->payments()->sum('amount'));
        $this->assertSame(0, $order->fresh()->remainingAmount());
    }

    public function test_pickup_status_changes_are_visible_in_customer_tracking(): void
    {
        $order = $this->order();
        $component = Livewire::test(CustomerOrder::class, ['token' => $order->slug])->assertSee('Good things are on the way.');
        $order->update(['status' => 'ready']);
        $component->call('$refresh')->assertSee('Your food is ready.');
        $this->get('/order/not-a-token')->assertNotFound();
    }
}
