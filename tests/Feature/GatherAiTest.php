<?php

namespace Tests\Feature;

use App\Livewire\AiSettings;
use App\Models\Business;
use App\Models\BusinessAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GatherAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_and_manager_can_access_provider_settings(): void
    {
        $this->withoutVite();
        $business = Business::create(['name' => 'Joy']);
        foreach (['owner', 'manager', 'admin', 'cashier'] as $role) {
            $user = User::factory()->create();
            $business->users()->attach($user, ['role' => $role]);
            $response = $this->actingAs($user)->get(route('business.ai', $business));
            if (in_array($role, ['owner', 'manager'], true)) {
                $response->assertOk()->assertSee('Provider API keys');
            } else {
                $response->assertForbidden();
                $this->post(route('business.ai.provider', [$business, 'openai']), ['action' => 'remove'])->assertForbidden();
            }
        }
    }

    public function test_saving_a_verified_key_enables_translation_and_tracks_usage(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/v1/models/*' => Http::response(['id' => 'gpt-4.1-mini']),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"fil":{"name":"Inihaw na manok","description":"May kanin."}}']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
        ]);
        $business = Business::create(['name' => 'Joy']);
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => 'manager']);
        $product = $business->products()->create(['name' => 'Grilled chicken', 'category' => 'Bowls', 'description' => 'Served with rice.', 'price' => 18900]);
        $key = 'test-key-123456789';
        $this->actingAs($user)->post(route('business.ai.provider', [$business, 'openai']), ['action' => 'save', 'api_key' => $key])
            ->assertRedirect();
        $this->assertStringNotContainsString($key, BusinessAiSetting::findOrFail($business->id)->getRawOriginal('credentials'));
        $this->get(route('business.ai', $business))->assertOk()->assertDontSee($key)->assertSee('6789');

        Livewire::test(AiSettings::class, ['business' => $business])
            ->set('productId', $product->id)
            ->call('generateDrafts')
            ->assertSet('drafts.fil.name', 'Inihaw na manok')
            ->call('storeDraft', 'fil')
            ->assertHasNoErrors()
            ->call('storeDraft', 'fil', true)
            ->assertHasNoErrors()
            ->call('setEnabled', false)
            ->call('generateDrafts')
            ->assertHasErrors('ai')
            ->call('setEnabled', true)
            ->set('monthlyLimit', '0.00')
            ->call('saveBudget')
            ->call('generateDrafts')
            ->assertHasErrors('ai');
        $this->assertSame('Inihaw na manok', $product->refresh()->menuText('name', 'fil'));
        $this->assertDatabaseHas('business_ai_usage', ['helper' => 'menu', 'cost_micros' => 120, 'status' => 'complete']);
        $this->assertSame(1, DB::table('business_ai_usage')->count());
        Http::assertSentCount(2);
    }
}
