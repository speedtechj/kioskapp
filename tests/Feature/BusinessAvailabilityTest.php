<?php

namespace Tests\Feature;

use App\Filament\Pages\Businesses;
use App\Livewire\CustomerMenu;
use App\Livewire\PaymentDesk;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_pause_and_reopen_only_the_selected_business(): void
    {
        $this->withoutVite();
        $business = Business::create(['name' => 'Test Restaurant']);
        $other = Business::create(['name' => 'Other Restaurant']);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner']);
        $kiosk = $business->kiosks()->create(['name' => 'Main']);
        $table = $kiosk->tables()->create(['name' => 'Table 1', 'token' => Str::uuid()->toString()]);
        $menu = route('menu', [$business, $kiosk, $table]);

        $this->actingAs($owner)->get('/control/businesses')->assertForbidden();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get('/control/businesses')->assertOk()->assertSee('Change status');
        Livewire::test(Businesses::class)
            ->callAction('changeAvailability', data: ['availability' => 'coming_soon'], arguments: ['business' => $business->id])->assertHasNoErrors();

        $this->assertSame('coming_soon', $business->fresh()->availability);
        $this->assertSame('live', $other->fresh()->availability);
        $otherKiosk = $other->kiosks()->create(['name' => 'Other kiosk']);
        $this->get(route('takeout', [$other, $otherKiosk]))->assertOk();
        $this->assertDatabaseHas('business_audit_events', [
            'business_id' => $business->id, 'actor_id' => $admin->id, 'action' => 'Business availability changed',
        ]);

        $this->get($menu)->assertStatus(503)->assertSee('We’re opening soon')->assertDontSee('Try again');
        $this->get('/menu/'.$table->token)->assertStatus(503);
        $this->get(route('takeout', [$business, $kiosk]))->assertStatus(503);
        $this->get(route('workspace', $business))->assertStatus(503)->assertSee('Workspace paused');
        $this->get(route('pos', [$business, $kiosk]))->assertStatus(503)->assertSee('Point of Sale paused');
        $this->get(route('kitchen', [$business, $kiosk]))->assertStatus(503)->assertSee('Kitchen display paused');

        Livewire::test(Businesses::class)
            ->callAction('changeAvailability', data: ['availability' => 'live'], arguments: ['business' => $business->id])->assertHasNoErrors();

        $this->get($menu)->assertOk();
    }

    public function test_open_livewire_tabs_cannot_submit_orders_or_payments_after_pause(): void
    {
        $this->withoutVite();
        $business = Business::create(['name' => 'Restaurant']);
        $kiosk = $business->kiosks()->create(['name' => 'Main']);
        $table = $kiosk->tables()->create(['name' => 'Table 1', 'token' => Str::uuid()->toString()]);
        $customer = Livewire::test(CustomerMenu::class, compact('business', 'kiosk', 'table'));
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner']);
        $order = Order::create([
            'business_id' => $business->id, 'kiosk_id' => $kiosk->id, 'token' => Str::uuid()->toString(),
            'request_key' => Str::uuid()->toString(), 'source' => 'qr', 'status' => 'received',
            'subtotal' => 10000, 'total' => 10000,
        ]);
        $this->actingAs($owner);
        $cashier = Livewire::test(PaymentDesk::class, compact('business', 'kiosk', 'order'));
        $business->forceFill(['availability' => 'maintenance'])->save();

        $customer->call('submit')->assertStatus(503);
        $cashier->call('record')->assertStatus(503);
        $this->get(route('payments', [$business, $kiosk, $order]))->assertStatus(503)->assertSee('Payments paused')->assertSee('Try again');
        $this->getJson(route('tracking', $order->slug))->assertStatus(503)->assertJsonPath('availability', 'maintenance');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_payments', 0);
    }
}
