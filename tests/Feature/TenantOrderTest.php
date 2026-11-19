<?php

namespace Tests\Feature;

use App\Filament\Pages\Businesses;
use App\Livewire\CustomerMenu;
use App\Livewire\Kitchen;
use App\Livewire\Terminal;
use App\Livewire\Workspace;
use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PlaceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TenantOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Business $other;

    private Kiosk $kiosk;

    private Kiosk $second;

    private Kiosk $foreign;

    private Product $product;

    private Product $foreignProduct;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->business = Business::create(['name' => 'Gather', 'slug' => 'gather']);
        $this->other = Business::create(['name' => 'Other', 'slug' => 'other']);
        $this->kiosk = $this->business->kiosks()->create(['name' => 'Main', 'service_charge_bps' => 600]);
        $this->second = $this->business->kiosks()->create(['name' => 'Second']);
        $this->foreign = $this->other->kiosks()->create(['name' => 'Foreign']);
        $this->product = $this->business->products()->create(['name' => 'Rice bowl', 'category' => 'Bowls', 'price' => 18900, 'image' => '/images/bowl.jpg']);
        $this->kiosk->products()->attach($this->product, ['stock' => 10]);
        $this->second->products()->attach($this->product, ['stock' => 10]);
        $this->foreignProduct = $this->other->products()->create(['name' => 'Secret coffee', 'category' => 'Drinks', 'price' => 9000]);
        $this->foreign->products()->attach($this->foreignProduct, ['stock' => 10]);
        $this->owner = User::factory()->create();
        $this->cashier = User::factory()->create();
        $this->business->users()->attach($this->owner, ['role' => 'owner']);
        $this->business->users()->attach($this->cashier, ['role' => 'cashier']);
        $this->cashier->kiosks()->attach($this->kiosk);
    }

    public function test_guest_cannot_enter_operations(): void
    {
        $this->get(route('workspace', $this->business))->assertRedirect('/login');
        $this->get(route('pos', [$this->business, $this->kiosk]))->assertRedirect('/login');
    }

    public function test_business_owner_cannot_read_another_tenant(): void
    {
        $this->actingAs($this->owner)->get(route('workspace', $this->other))->assertForbidden();
        $this->get(route('pos', [$this->other, $this->foreign]))->assertForbidden();
    }

    public function test_cashier_is_limited_to_assigned_kiosks_and_cannot_manage_staff(): void
    {
        $this->actingAs($this->cashier)->get(route('pos', [$this->business, $this->kiosk]))->assertOk();
        $this->get(route('pos', [$this->business, $this->second]))->assertForbidden();
        $this->get(route('workspace', $this->business))->assertForbidden();
        $this->get(route('workspace', ['business' => $this->business, 'tab' => 'orders']))->assertOk();
    }

    public function test_owner_dashboard_and_sections_render(): void
    {
        $this->actingAs($this->owner)->get(route('workspace', $this->business))->assertOk()->assertSee('Good ')->assertDontSee('Secret coffee');
        $component = Livewire::test(Workspace::class, ['business' => $this->business]);
        foreach (['orders', 'menu', 'inventory', 'kiosks', 'staff', 'reports', 'overview'] as $section) {
            $component->call('navigate', $section)->assertStatus(200);
        }
    }

    public function test_selected_kiosk_is_reauthorized_on_livewire_requests(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Workspace::class, ['business' => $this->business])->set('selectedKiosk', $this->foreign->id)->assertForbidden();
    }

    public function test_public_qr_menu_is_scoped_and_rejects_foreign_items(): void
    {
        $table = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        $this->get(route('menu',[$table->kiosk->business,$table->kiosk,$table]))->assertOk()->assertSee('Rice bowl')->assertDontSee('Secret coffee');
        Livewire::test(CustomerMenu::class, ['business'=>$table->kiosk->business,'kiosk'=>$table->kiosk,'table'=>$table])->call('add', $this->foreignProduct->id)->assertNotFound();
    }

    public function test_order_uses_database_prices_reserves_stock_and_is_idempotent(): void
    {
        $table = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        $key = (string) Str::uuid();
        $service = app(PlaceOrder::class);
        $o = $service->handle($this->kiosk, [$this->product->id => 2], $key, $table);
        $repeat = $service->handle($this->kiosk, [$this->product->id => 2], $key, $table);
        $this->assertSame($o->id, $repeat->id);
        $this->assertSame(37800, $o->subtotal);
        $this->assertSame(2268, $o->service_charge);
        $this->assertSame(40068, $o->total);
        $this->assertSame('unpaid', $o->payment_status);
        $this->assertSame(8, $this->kiosk->products()->first()->pivot->stock);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_foreign_product_injected_into_cart_does_not_create_an_order(): void
    {
        try {
            app(PlaceOrder::class)->handle($this->kiosk, [$this->foreignProduct->id => 1], (string) Str::uuid());
            $this->fail('Expected invalid cart');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->kiosk->products()->first()->pivot->stock);
    }

    public function test_overselling_is_rejected_and_stock_is_unchanged(): void
    {
        try {
            app(PlaceOrder::class)->handle($this->kiosk, [$this->product->id => 11], (string) Str::uuid());
            $this->fail('Expected stock error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->errors());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->kiosk->products()->first()->pivot->stock);
    }

    public function test_pos_checkout_records_payment_and_clears_cart(): void
    {
        $this->actingAs($this->cashier);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->product->id)->call('checkout')->assertHasNoErrors()->assertSet('cart', []);
        $this->assertDatabaseHas('orders', ['payment_status' => 'paid', 'source' => 'pos', 'kiosk_id' => $this->kiosk->id, 'total' => 20034]);
    }

    public function test_revoked_membership_cannot_continue_using_an_open_terminal(): void
    {
        $this->actingAs($this->cashier);
        $component = Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('add', $this->product->id);
        $this->business->users()->detach($this->cashier);
        $component->call('checkout')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_submit_redirects_to_private_order_token(): void
    {
        $t = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        Livewire::test(CustomerMenu::class, ['business'=>$t->kiosk->business,'kiosk'=>$t->kiosk,'table'=>$t])->call('add', $this->product->id)->set('name', 'Guest')->call('submit')->assertHasNoErrors()->assertRedirect(route('tracking', Order::first()->slug));
        $this->get(route('tracking', Order::first()->slug))->assertOk()->assertSee('Rice bowl');
    }

    public function test_inactive_table_cannot_order(): void
    {
        $t = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid(), 'active' => false]);
        $this->get(route('menu',[$t->kiosk->business,$t->kiosk,$t]))->assertNotFound();
    }

    public function test_kitchen_can_advance_only_own_assigned_kiosk_orders(): void
    {
        $user = User::factory()->create();
        $this->business->users()->attach($user, ['role' => 'kitchen']);
        $user->kiosks()->attach($this->kiosk);
        $order = app(PlaceOrder::class)->handle($this->kiosk, [$this->product->id => 1], (string) Str::uuid());
        $other = app(PlaceOrder::class)->handle($this->second, [$this->product->id => 1], (string) Str::uuid());
        $this->actingAs($user);
        Livewire::test(Kitchen::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('advance', $order->id)->assertSee('Preparing');
        $this->assertSame('preparing', $order->fresh()->status);
        Livewire::test(Kitchen::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('advance', $other->id)->assertNotFound();
    }

    public function test_qr_is_real_svg_and_not_accessible_to_other_tenants(): void
    {
        $t = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        $this->actingAs($this->owner)->get(route('qr',[$t->kiosk->business,$t->kiosk,$t]))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->business->users()->detach($this->owner);
        $this->get(route('qr',[$t->kiosk->business,$t->kiosk,$t]))->assertForbidden();
    }

    public function test_owner_cannot_access_platform_panel(): void
    {
        $this->actingAs($this->owner)->get('/control/businesses')->assertForbidden();
    }

    public function test_staff_cannot_assign_cross_tenant_kiosks(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Workspace::class, ['business' => $this->business])->call('openForm', 'staff')->set('form', ['name' => 'New Staff', 'email' => 'staff@example.test', 'password' => 'LongPassword!2026', 'role' => 'cashier', 'permissions' => [], 'kiosk_ids' => [$this->foreign->id]])->call('save')->assertHasErrors(['form.kiosk_ids.0']);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.test']);
    }

    public function test_kiosk_creation_and_menu_creation_stay_inside_business(): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Workspace::class, ['business' => $this->business])->call('openForm', 'kiosk')->set('form', ['name' => 'Third', 'address' => 'Test', 'active' => true, 'service_charge' => 6])->call('save')->assertHasNoErrors();
        $new = $this->business->kiosks()->where('name', 'Third')->firstOrFail();
        $this->assertSame(8, $new->tables()->count());
        $this->assertSame(0, $new->products()->first()->pivot->stock);
        Livewire::test(Workspace::class, ['business' => $this->business])->call('openForm', 'product')->set('form', ['name' => 'New meal', 'category' => 'Bowls', 'description' => 'Good food', 'price' => 199, 'image' => '/images/bowl.jpg', 'active' => true])->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('products', ['business_id' => $this->business->id, 'name' => 'New meal', 'price' => 19900]);
        $this->assertSame(1, $this->foreign->products()->count());
    }

    public function test_platform_admin_can_provision_an_isolated_business(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($u)->get('/control/businesses')->assertOk()->assertSee('Create a business');
        Livewire::test(Businesses::class)->set('name', 'New Restaurant')->set('ownerName', 'New Owner')->set('email', 'newowner@example.test')->set('password', 'OwnerPassword!2026')->call('createBusiness')->assertHasNoErrors();
        $b = Business::where('name', 'New Restaurant')->firstOrFail();
        $this->assertSame('owner', $b->users()->first()->pivot->role);
        $this->assertSame(0, $b->kiosks()->count());
    }

    public function test_revoking_staff_access_preserves_other_memberships(): void
    {
        $this->other->users()->attach($this->cashier, ['role' => 'cashier']);
        $this->cashier->kiosks()->attach($this->foreign);
        $this->actingAs($this->owner);
        Livewire::test(Workspace::class, ['business' => $this->business])->call('revoke', $this->cashier->id)->assertStatus(200);
        $this->assertFalse($this->cashier->businesses()->where('businesses.id', $this->business->id)->exists());
        $this->assertTrue($this->cashier->businesses()->where('businesses.id', $this->other->id)->exists());
        $this->assertTrue($this->cashier->kiosks()->where('kiosks.id', $this->foreign->id)->exists());
    }

    public function test_cashier_records_cash_for_existing_qr_order(): void
    {
        $order = app(PlaceOrder::class)->handle($this->kiosk, [$this->product->id => 1], (string) Str::uuid());
        $this->actingAs($this->cashier);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])->call('markPaid', $order->id)->assertStatus(200);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_customer_manifest_starts_at_its_own_table_menu(): void
    {
        $t = $this->kiosk->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        $this->get(route('menu.manifest',[$t->kiosk->business,$t->kiosk,$t]))->assertOk()->assertJsonPath('start_url', route('menu',[$t->kiosk->business,$t->kiosk,$t],false));
    }

    public function test_noncanonical_product_identifiers_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(PlaceOrder::class)->handle($this->kiosk, ['0'.$this->product->id => 1], (string) Str::uuid());
    }

    public function test_a_table_from_another_kiosk_cannot_be_used_at_checkout(): void
    {
        $table = $this->second->tables()->create(['name' => 'Second table', 'token' => (string) Str::uuid()]);
        $this->actingAs($this->owner);
        Livewire::test(Terminal::class, ['business' => $this->business, 'kiosk' => $this->kiosk])
            ->call('add', $this->product->id)->set('tableId', $table->id)->call('checkout')->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }
}
