<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    protected $fillable = ['order_id', 'batch_key', 'sequence', 'method', 'amount', 'reference', 'recorded_by', 'confirmed_at', 'register_shift_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
