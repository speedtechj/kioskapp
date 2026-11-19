<?php

namespace Tests\Feature;

use App\Livewire\BusinessSettings;
use App\Livewire\Workspace;
use App\Models\Business;
use App\Models\User;
use App\Services\PlaceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SlugUrlsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutVite();
        $b = Business::create(['name' => 'Gather']);
        $k = $b->kiosks()->create(['name' => 'Main Branch']);
        $t = $k->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
        $u = User::factory()->create();
        $b->users()->attach($u, ['role' => 'owner']);

        return [$b, $k, $t, $u];
    }

    public function test_readable_urls_and_legacy_redirects(): void
    {
        [$b,$k,$t,$u] = $this->fixture();
        $this->assertSame('gather', $b->slug);
        $this->assertSame('main-branch', $k->slug);
        $this->assertSame('table-01', $t->slug);
        $this->get(route('menu', [$b, $k, $t]))->assertOk();
        $this->get('/menu/'.$t->token)->assertRedirect(route('menu', [$b, $k, $t]));
        $this->get('/takeout/'.$k->takeout_token)->assertRedirect(route('takeout', [$b, $k]));
        $this->actingAs($u)->get('/business/'.$b->id.'/kiosk/'.$k->id.'/pos')->assertRedirect(route('pos', [$b, $k]));
        $this->get('/qr/'.$t->id)->assertRedirect(route('qr', [$b, $k, $t]));
        $this->get(route('workspace', $b))->assertOk()->assertSee('Business settings');
    }

    public function test_nested_bindings_reject_other_tenants_and_kiosks(): void
    {
        [$b,$k,$t,$u] = $this->fixture();
        $other = Business::create(['name' => 'Other']);
        $foreign = $other->kiosks()->create(['name' => 'Foreign']);
        $second = $b->kiosks()->create(['name' => 'Second']);
        $this->get('/takeout/gather/foreign')->assertNotFound();
        $this->get('/menu/gather/second/table-01')->assertNotFound();
        $this->actingAs($u)->get(route('pos', [$other, $foreign]))->assertForbidden();
    }

    public function test_slug_rename_keeps_old_links_and_reserves_old_slug(): void
    {
        [$b,$k,$t] = $this->fixture();
        $old = route('menu', [$b, $k, $t]);
        $k->update(['slug' => 'downtown']);
        $t->update(['slug' => 'patio']);
        $b->update(['slug' => 'new-gather']);
        $this->get($old)->assertRedirect(route('menu', [$b, $k, $t]));
        $this->expectException(ValidationException::class);
        $b->kiosks()->create(['name' => 'Another', 'slug' => 'main-branch']);
    }

    public function test_duplicate_names_get_unique_slugs_within_parent(): void
    {
        [$b,$k] = $this->fixture();
        $duplicate = $b->kiosks()->create(['name' => 'Main Branch']);
        $this->assertSame('main-branch-2', $duplicate->slug);
        $other = Business::create(['name' => 'Other']);
        $this->assertSame('main-branch', $other->kiosks()->create(['name' => 'Main Branch'])->slug);
    }

    public function test_business_kiosk_table_fields_are_editable(): void
    {
        [$b,$k,$t,$u] = $this->fixture();
        $this->actingAs($u);
        Livewire::test(BusinessSettings::class, ['business' => $b])->set('slug', 'gather-cafe')->call('save')->assertHasNoErrors();
        $this->assertSame('gather-cafe', $b->fresh()->slug);
        Livewire::test(Workspace::class, ['business' => $b->fresh()])->call('openForm', 'table', $t->id)->set('form.slug', 'patio-01')->call('save')->assertHasNoErrors();
        $this->assertSame('patio-01', $t->fresh()->slug);
        Livewire::test(Workspace::class, ['business' => $b->fresh()])->call('openForm', 'kiosk', $k->id)->set('form.slug', 'downtown')->call('save')->assertHasNoErrors();
        $this->assertSame('downtown', $k->fresh()->slug);
    }

    public function test_order_links_use_private_slugs_and_legacy_tokens_redirect(): void
    {
        [$b,$k] = $this->fixture();
        $p = $b->products()->create(['name' => 'Bowl', 'category' => 'Bowls', 'price' => 100]);
        $k->products()->attach($p, ['stock' => 10]);
        $o = app(PlaceOrder::class)->handle($k, [$p->id => 1], (string) Str::uuid());
        $this->assertMatchesRegularExpression('/^order-[a-f0-9]{32}$/', $o->slug);
        $this->get('/order/'.$o->id)->assertNotFound();
        $this->get('/order/'.$o->token)->assertRedirect(route('tracking', $o->slug));
        $this->get(route('tracking', $o->slug))->assertOk();
    }

    public function test_alias_scope_cannot_leak_to_other_business(): void
    {
        [$b,$k] = $this->fixture();
        $k->update(['slug' => 'downtown']);
        $other = Business::create(['name' => 'Other']);
        $second = $other->kiosks()->create(['name' => 'Main Branch']);
        $this->get('/takeout/other/main-branch')->assertOk()->assertSee('Takeout at Other');
        $this->get('/takeout/other/downtown')->assertNotFound();
    }
}
