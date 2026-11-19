<?php

namespace App\Models;

use App\Models\Concerns\HasReadableSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasReadableSlug;

    protected $fillable = ['name', 'slug'];

    public function logoUrl(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    public function kiosks(): HasMany
    {
        return $this->hasMany(Kiosk::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'permissions']);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
