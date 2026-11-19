<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class OrderAdjustments
{
    public static function totals(int $subtotal, int $complimentary, string $type, string $value, int $serviceRate): array
    {
        $eligible = $subtotal - $complimentary;
        $discount = 0;
        if ($value !== '') {
            if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $value)) {
                throw ValidationException::withMessages(['discountValue' => 'Enter a discount with up to two decimal places.']);
            }
            $amount = Money::cents($value);
            $discount = match ($type) {
                'percent' => $amount <= 10000 ? intdiv($eligible * $amount + 5000, 10000) : -1,
                'fixed' => $amount,
                default => -1,
            };
            if ($discount < 0 || $discount > $eligible) {
                throw ValidationException::withMessages(['discountValue' => 'Discount must be within 100% or the eligible order amount.']);
            }
        }
        $net = $eligible - $discount;
        $fee = (int) round($net * $serviceRate / 10000);

        return ['discount' => $discount, 'fee' => $fee, 'total' => $net + $fee];
    }
}
