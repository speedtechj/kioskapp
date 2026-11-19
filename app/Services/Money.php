<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['payments' => 'Enter a positive amount with at most two decimal places.']);
        }
        [$whole,$decimal] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }

    public static function input(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
