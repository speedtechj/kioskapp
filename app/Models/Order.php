<?php

namespace App\Models;

use App\Models\Concerns\HasPrivateOrderSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasPrivateOrderSlug;

    protected $fillable = ['token', 'request_key', 'business_id', 'kiosk_id', 'dining_table_id', 'user_id', 'source', 'status', 'payment_status', 'payment_method', 'subtotal', 'complimentary_amount', 'discount_amount', 'service_charge', 'total', 'customer_name', 'notes'];

    protected function casts(): array
    {
        return ['adjustment_approvals' => 'array', 'topping_lines' => 'array'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function paidAmount(): int
    {
        $sum = $this->relationLoaded('payments') ? (int) $this->payments->sum('amount') : (int) $this->payments()->sum('amount');

        return $sum === 0 && $this->payment_status === 'paid' ? (int) $this->total : $sum;
    }

    public function remainingAmount(): int
    {
        return $this->status === 'cancelled' ? 0 : max(0, (int) $this->total - $this->paidAmount());
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
