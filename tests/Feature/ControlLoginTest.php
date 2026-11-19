<?php

namespace Tests\Feature;

use App\Filament\Auth\ControlLogin;
use App\Models\PlatformBranding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ControlLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_login_renders_the_split_layout_and_configured_brand(): void
    {
        $branding = new PlatformBranding(['name' => 'Joy’s Platform']);
        $branding->id = 1;
        $branding->save();

        $this->get('/control/login')->assertOk()
            ->assertSee('gather-control-login')->assertSee('control-counter.webp')
            ->assertSee('Joy’s Platform')->assertSee('Remember me');
    }

    public function test_control_login_uses_filament_panel_access_and_authentication(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $member = User::factory()->create(['password' => 'example-password']);
        $admin = User::factory()->create(['password' => 'example-password', 'is_super_admin' => true]);

        Livewire::test(ControlLogin::class)->set('data.email', $member->email)
            ->set('data.password', 'example-password')->call('authenticate')->assertHasErrors();
        $this->assertGuest();

        Livewire::test(ControlLogin::class)->set('data.email', $admin->email)
            ->set('data.password', 'example-password')->set('data.remember', true)
            ->call('authenticate')->assertHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }
}
