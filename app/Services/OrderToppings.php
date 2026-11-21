<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderToppings
{
    public static function catalog(int $businessId): Builder
    {
        return DB::table('toppings')->where('business_id', $businessId);
    }

    public static function lines(int $businessId, array $quantities, bool $lock = false): array
    {
        if ($quantities === []) {
            return [];
        }

        foreach ($quantities as $id => $quantity) {
            if (! is_int($id) || $id < 1 || ! is_int($quantity) || $quantity < 1 || $quantity > 99 || count($quantities) > 50) {
                throw ValidationException::withMessages(['toppings' => 'Choose valid topping quantities.']);
            }
        }

        $rows = self::catalog($businessId)->where('active', true)->whereIn('id', array_keys($quantities))
            ->orderBy('id')->when($lock, fn (Builder $query) => $query->lockForUpdate())->get();

        if ($rows->count() !== count($quantities)) {
            throw ValidationException::withMessages(['toppings' => 'A selected topping is unavailable. Remove it to continue.']);
        }

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'unit_price' => $row->price,
            'quantity' => $quantities[$row->id],
            'total' => $row->price * $quantities[$row->id],
        ])->all();
    }
}
