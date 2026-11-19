<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspacePwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_and_customer_menus_have_separate_installable_apps(): void
    {
        $this->withoutVite();
        $business = Business::create(['name' => 'Joy']);
        $kiosk = $business->kiosks()->create(['name' => 'Main']);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner']);

        $this->get(route('takeout', [$business, $kiosk]))->assertOk()
            ->assertSee(route('takeout.manifest', [$business, $kiosk]))
            ->assertSee('Continue ordering')
            ->assertDontSee('staff.webmanifest');

        $this->actingAs($owner)->get(route('workspace', $business))->assertOk()
            ->assertSee('staff.webmanifest')
            ->assertSee('Mobile workspace navigation')
            ->assertSee(route('pos', [$business, $kiosk]))
            ->assertSee('Install workspace');
        $this->get(route('pos', [$business, $kiosk]))->assertOk()
            ->assertSee('View cart')
            ->assertSee('Continue to payment');
    }

    public function test_qr_preview_and_svg_use_the_public_request_origin(): void
    {
        $this->withoutVite();
        config(['app.url' => 'http://gather.test', 'trustedproxy.proxies' => '127.0.0.1']);
        $business = Business::create(['name' => 'Joy']);
        $kiosk = $business->kiosks()->create(['name' => 'Main']);
        $table = $kiosk->tables()->create(['name' => 'Patio', 'token' => (string) Str::uuid()]);
        $owner = User::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner']);
        $writer = new Writer(new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd));

        $this->actingAs($owner);
        foreach (['https://orders.example.test', 'https://preview.example.test'] as $origin) {
            $this->withHeaders([
                'X-Forwarded-Host' => parse_url($origin, PHP_URL_HOST),
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ]);
            $takeoutUrl = $origin.route('takeout', [$business, $kiosk], false);
            $tableUrl = $origin.route('menu', [$business, $kiosk, $table], false);
            $this->get(route('kiosk.brand', [$business, $kiosk], false))->assertOk()
                ->assertSee($takeoutUrl)->assertSee($tableUrl);
            $this->get(route('takeout.qr', [$business, $kiosk], false))->assertOk()
                ->assertContent($writer->writeString($takeoutUrl));
            $this->get(route('qr', [$business, $kiosk, $table], false))->assertOk()
                ->assertContent($writer->writeString($tableUrl));
        }
    }
}
