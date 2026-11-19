<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $attributes = ['active' => true];

    protected $fillable = ['business_id', 'name', 'category', 'description', 'price', 'image', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'visible_kiosk_ids' => 'array', 'translation_drafts' => 'array', 'published_translations' => 'array'];
    }

    public function menuText(string $field, string $language): string
    {
        return (string) ($this->published_translations[$language][$field] ?? $this->{$field});
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function kiosks(): BelongsToMany
    {
        return $this->belongsToMany(Kiosk::class)->withPivot('stock');
    }
}
