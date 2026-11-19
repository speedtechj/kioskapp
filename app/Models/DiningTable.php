<?php

namespace App\Models;

use App\Models\Concerns\HasReadableSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiningTable extends Model
{
    use HasReadableSlug;

    protected $attributes = ['active' => true];

    protected $fillable = ['slug', 'kiosk_id', 'name', 'token', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }
}
