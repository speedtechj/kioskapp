<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_collections_use_their_respective_dates_with_kiosk_access_and_csv(): void
    {
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(12, 0));
        $business = Business::create(['name' => 'Restaurant']);
        $kiosk = $business->kiosks()->create(['name' => 'Main kiosk']);
        $other = $business->kiosks()->create(['name' => 'Private kiosk']);
        $manager = User::factory()->create();
        $business->users()->attach($manager, ['role' => 'manager', 'permissions' => json_encode(['reports.view'])]);
        $manager->kiosks()->attach($kiosk);
        $order = Order::create([
            'business_id' => $business->id, 'kiosk_id' => $kiosk->id, 'token' => Str::uuid()->toString(),
            'request_key' => Str::uuid()->toString(), 'source' => 'qr', 'status' => 'cancelled',
            'payment_status' => 'refunded', 'subtotal' => 12000, 'discount_amount' => 2000, 'total' => 10000,
        ]);
        $order->forceFill(['created_at' => now()->subDay()])->save();
        $order->payments()->create(['batch_key' => Str::uuid()->toString(), 'sequence' => 0,
            'amount' => 10000, 'method' => 'cash', 'confirmed_at' => now()->subDay(),
        ])->forceFill(['refunded_at' => now()])->save();

        $this->actingAs($manager)->get(route('reports.sales', [$business, 'period' => 'yesterday']))
            ->assertOk()->assertViewHas('report', fn ($report) => $report['totals']['net'] === 10000 && $report['totals']['paid_orders'] === 1);
        $this->get(route('reports.sales', [$business, 'period' => 'today']))
            ->assertOk()->assertDontSee('Private kiosk')
            ->assertViewHas('report', fn ($report) => $report['totals']['net'] === -10000 && $report['methods']->isEmpty());
        $this->get(route('reports.sales', [$business, 'kiosk' => $other->id]))->assertForbidden();
        $csv = $this->get(route('reports.sales', [$business, 'period' => 'yesterday', 'format' => 'csv']));
        $csv->assertOk()->assertDownload('sales-summary-2026-11-18-2026-11-18.csv');
        $this->assertStringContainsString('Total,Total,120.00,20.00,0.00,100.00,1,100.00', $csv->streamedContent());
    }
}
