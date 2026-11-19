<?php

namespace App\Filament\Pages;

use App\Models\PlatformBranding;
use App\Models\User;
use App\Services\BrandImage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithFileUploads;

class PlatformSettings extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.platform-settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 20;

    public string $tab = 'branding';

    public string $platformName = '';

    public $logoUpload = null;

    public $faviconUpload = null;

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPassword_confirmation = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->platformName = PlatformBranding::current()->name;
    }

    public function saveBranding(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->platformName = trim($this->platformName);
        $this->validate(['platformName' => 'required|string|max:100', 'logoUpload' => BrandImage::rules(), 'faviconUpload' => BrandImage::rules(32, true)]);
        $data = ['name' => $this->platformName];
        foreach (['logo', 'favicon'] as $field) {
            if ($this->{$field.'Upload'}) {
                $data[$field] = BrandImage::store($this->{$field.'Upload'}, 'platform-branding');
            }
        }
        $branding = PlatformBranding::find(1) ?? new PlatformBranding;
        $branding->id = 1;
        $branding->fill($data)->save();
        Notification::make()->title('Platform branding saved')->success()->send();
        $this->redirect(static::getUrl());
    }

    public function createSuperAdmin(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->adminName = trim($this->adminName);
        $this->adminEmail = strtolower(trim($this->adminEmail));
        $this->validate([
            'adminName' => 'required|string|max:100',
            'adminEmail' => 'required|email|max:254|unique:users,email',
            'adminPassword' => ['required', 'string', 'min:12', 'confirmed', function ($field, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
        ]);

        $user = new User(['name' => $this->adminName, 'email' => $this->adminEmail, 'password' => $this->adminPassword]);
        $user->is_super_admin = true;
        $user->save();
        $this->reset('adminName', 'adminEmail', 'adminPassword', 'adminPassword_confirmation');
        Notification::make()->title('Super admin created')->success()->send();
    }

    public function getSuperAdmins()
    {
        abort_unless(static::canAccess(), 403);

        return User::where('is_super_admin', true)->orderBy('name')->get(['id', 'name', 'email']);
    }
}
