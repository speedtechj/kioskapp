<?php

namespace Tests\Feature;

use App\Livewire\CustomerOrder;
use App\Livewire\PaymentDesk;
use App\Livewire\Workspace;
use App\Models\Business;
use App\Models\User;
use App\Services\PlaceOrder;
use App\Services\RecordPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CancellationMenuTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $b = Business::create(['name' => 'Restaurant']);
        $a = $b->kiosks()->create(['name' => 'First']);
        $k = $b->kiosks()->create(['name' => 'Second']);
        $p = $b->products()->create(['name' => 'Island bowl', 'category' => 'Bowl', 'price' => 10000, 'image' => '/images/bowl.jpg']);
        foreach ([$a, $k] as $branch) {
            $branch->products()->attach($p, ['stock' => 5]);
        }
        $owner = User::factory()->create();
        $b->users()->attach($owner, ['role' => 'owner']);

        return [$b, $a, $k, $p, $owner];
    }

    public function test_cancel_and_refund_split_tenders_once(): void
    {
        [$b,$k,,$p,$owner] = $this->fixture();
        $order = app(PlaceOrder::class)->handle($k, [$p->id => 1], (string) Str::uuid());
        $this->actingAs($owner);
        app(RecordPayment::class)->handle($order, [['method' => 'cash', 'amount' => '20'], ['method' => 'gcash', 'amount' => '30']], (string) Str::uuid());
        auth()->logout();
        $tracking = Livewire::test(CustomerOrder::class, ['token' => $order->slug]);
        $tracking->assertSee('Cancel order')->call('cancel')->call('cancel')->assertSee('Refund due');
        $this->assertSame(5, $k->products()->first()->pivot->stock);
        $this->assertSame('refund_pending', $order->fresh()->payment_status);
        $this->actingAs($owner);
        $desk = Livewire::test(PaymentDesk::class, ['business' => $b, 'kiosk' => $k, 'order' => $order->fresh()]);
        foreach ($order->payments as $payment) {
            $desk->call('refund', $payment->id)->call('refund', $payment->id);
        }
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame(5000, (int) $order->payments()->whereNotNull('refunded_at')->sum('amount'));
        $this->expectException(ValidationException::class);
        app(RecordPayment::class)->handle($order, [['method' => 'cash', 'amount' => '1']], (string) Str::uuid());
    }

    public function test_preparation_blocks_customer_and_staff_cancellation_keeps_prepared_stock_used(): void
    {
        [$b,$k,,$p,$owner] = $this->fixture();
        $order = app(PlaceOrder::class)->handle($k, [$p->id => 1], (string) Str::uuid());
        $order->update(['status' => 'preparing']);
        Livewire::test(CustomerOrder::class, ['token' => $order->slug])->call('cancel')->assertHasErrors('cancel');
        $this->actingAs($owner);
        Livewire::test(PaymentDesk::class, ['business' => $b, 'kiosk' => $k, 'order' => $order])->call('cancel')->assertSee('No money was collected');
        $this->assertSame(4, $k->products()->first()->pivot->stock);
    }

    public function test_selected_kiosks_control_display_and_stale_cart_checkout(): void
    {
        [$b,$a,$k,$p,$owner] = $this->fixture();
        $this->actingAs($owner);
        Livewire::test(Workspace::class, ['business' => $b])->call('openForm', 'product', $p->id)
            ->set('allMenuKiosks', false)->set('menuKiosks', [(string) $a->id])->call('save')->assertHasNoErrors();
        $this->get(route('takeout', [$b, $a]))->assertSee('Island bowl');
        $this->get(route('takeout', [$b, $k]))->assertDontSee('Island bowl');
        $this->get(route('pos', [$b, $k]))->assertDontSee('Island bowl');
        $this->assertSame(5, $k->products()->first()->pivot->stock);
        $this->expectException(ValidationException::class);
        app(PlaceOrder::class)->handle($k,[$p->id => 1],(string) Str::uuid());
    }
}
