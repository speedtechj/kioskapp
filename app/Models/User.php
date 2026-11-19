<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_super_admin' => false];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_super_admin' => 'boolean'];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->withPivot(['role', 'permissions']);
    }

    public function kiosks(): BelongsToMany
    {
        return $this->belongsToMany(Kiosk::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_super_admin;
    }
}
