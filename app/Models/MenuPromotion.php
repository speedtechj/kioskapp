<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MenuPromotion extends Model
{
    protected $fillable = ['business_id', 'name', 'percent', 'all_items', 'product_ids', 'enabled', 'announces_at', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'percent' => 'integer', 'all_items' => 'boolean', 'product_ids' => 'array', 'enabled' => 'boolean',
            'announces_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
        ];
    }

    public static function visibleFor(int $businessId): ?self
    {
        $now = now();

        return self::where('business_id', $businessId)->where('enabled', true)
            ->where('announces_at', '<=', $now)->where('ends_at', '>', $now)->first();
    }

    public function phase(): string
    {
        return now()->lt($this->starts_at) ? 'upcoming' : 'active';
    }

    public function eligible(Product $product): bool
    {
        return (int) $product->business_id === (int) $this->business_id
            && ($this->all_items || in_array($product->id, $this->product_ids, true));
    }

    public function unitSaving(int $regular): int
    {
        return intdiv($regular * $this->percent + 50, 100);
    }

    public function savings(Collection $products, array $cart): int
    {
        if ($this->phase() !== 'active') {
            return 0;
        }

        return $products->sum(fn (Product $product) => $this->eligible($product)
            ? $this->unitSaving($product->price) * ($cart[$product->id] ?? 0) : 0);
    }
}
