<?php

namespace App\Models;

use App\Models\Concerns\HasReadableSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Kiosk extends Model
{
    use HasReadableSlug;

    protected $attributes = ['active' => true, 'service_charge_bps' => 0];

    protected $fillable = ['slug', 'business_id', 'name', 'address', 'active', 'service_charge_bps'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Kiosk $kiosk) {
            if ($kiosk->archived_at) {
                $kiosk->active = false;
            }
        });

        static::creating(function (Kiosk $kiosk) {
            $kiosk->takeout_token = $kiosk->takeout_token ?: (string) Str::uuid();
        });
    }

    public function appLabel(): string
    {
        return $this->app_label ?: $this->name;
    }

    public function appIcon(int $size): string
    {
        return $this->app_icon_key
            ? asset("storage/kiosk-icons/{$this->business_id}/{$this->id}/{$this->app_icon_key}/{$size}.png")
            : asset("branding/bowl-{$size}.png");
    }

    public function cashierIcon(int $size): string
    {
        $key = $size === 32 ? $this->cashier_favicon_key : $this->cashier_icon_key;

        return $key
            ? asset("storage/cashier-icons/{$this->business_id}/{$this->id}/{$key}/{$size}.png")
            : $this->appIcon($size);
    }

    public function logoUrl(): string
    {
        return $this->app_icon_key
            ? $this->appIcon(192)
            : ($this->business->logoUrl() ?? $this->appIcon(192));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function visibleProducts(): BelongsToMany
    {
        return $this->products()->where(function ($query) {
            $query->whereNull('products.visible_kiosk_ids')
                ->orWhereJsonContains('products.visible_kiosk_ids', (int) $this->id);
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('stock');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
