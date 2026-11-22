<?php

namespace Tests\Feature;

use App\Livewire\CustomerMenu;
use App\Livewire\TakeoutMenu;
use App\Models\Business;
use App\Services\GatherAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AskJoyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_app_uses_current_catalog_and_respects_the_switch(): void
    {
        $this->withoutVite();
        $business = Business::create(['name' => 'Joy Rice Bowl']);
        $kiosk = $business->kiosks()->create(['name' => 'Garden']);
        $table = $kiosk->tables()->create(['name' => 'Table 4', 'token' => (string) Str::uuid()]);
        $item = $business->products()->create(['name' => 'Siomai Bowl', 'category' => 'Bowls', 'price' => 7500]);
        $soldOut = $business->products()->create(['name' => 'Sold-out Noodles', 'category' => 'Noodles', 'price' => 7000]);
        $kiosk->products()->attach([$item->id => ['stock' => 5], $soldOut->id => ['stock' => 0]]);
        $settings = GatherAi::settings($business->id);

        Http::preventStrayRequests();
        Http::fake(['api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['answer' => 'Try this bowl.', 'product_id' => $item->id])]]],
        ])]);

        $parameters = compact('business', 'kiosk');
        $takeout = Livewire::test(TakeoutMenu::class, $parameters)->assertDontSee('Ask Joy');
        $takeout->call('askJoy', 'Any bowls?')->assertHasNoErrors();
        Http::assertNothingSent();

        $settings->update(['enabled' => true, 'credentials' => ['groq' => 'test-customer-key'], 'helpers' => [
            'customer' => ['provider' => 'groq', 'enabled' => true],
        ]]);

        $dineIn = Livewire::test(CustomerMenu::class, $parameters + compact('table'))->assertSee('Ask Joy');
        $takeout->call('$refresh')->assertSee('Ask Joy')->assertDontSee('test-customer-key');
        $takeout->call('askJoy', 'May rice bowl?')->assertHasNoErrors();
        $reply = $dineIn->instance()->askJoy('May rice bowl?', app(GatherAi::class));
        $this->assertSame(7500, $reply['item']['total']);
        Http::assertSent(function ($request) use ($item, $soldOut) {
            $catalog = json_decode($request['messages'][1]['content'], true)['items'];

            return isset($catalog[$item->id]) && ! isset($catalog[$soldOut->id]);
        });

        $settings->update(['helpers' => ['customer' => ['provider' => 'groq', 'enabled' => false]]]);
        $takeout->call('askJoy', 'Any bowls?')->assertDontSee('Ask Joy');
        Http::assertSentCount(2);
    }
}
