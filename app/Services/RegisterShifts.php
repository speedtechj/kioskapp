<?php

namespace App\Services;

use App\Models\Kiosk;
use App\Models\OrderPayment;
use App\Models\RegisterShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterShifts
{
    public const DENOMINATIONS = [
        100000 => '₱1,000 bill', 50000 => '₱500 bill', 20000 => '₱200 bill', 10000 => '₱100 bill',
        5000 => '₱50 bill', '2000-bill' => '₱20 bill', '2000-coin' => '₱20 coin',
        1000 => '₱10 coin', 500 => '₱5 coin', 100 => '₱1 coin',
        25 => '25¢ coin', 10 => '10¢ coin', 5 => '5¢ coin', 1 => '1¢ coin',
    ];

    public static function active(int $kioskId): ?RegisterShift
    {
        return RegisterShift::where('kiosk_id', $kioskId)->whereNull('closed_at')->first();
    }

    // Call from a transaction holding the kiosk row lock.
    public static function requireOpen(int $kioskId): RegisterShift
    {
        return self::active($kioskId) ?? throw ValidationException::withMessages(['shift' => 'Open a shift to start selling or record payments.']);
    }

    public static function open(Kiosk $kiosk, int $cash): void
    {
        Access::authorize($kiosk->business_id, 'orders.pay', $kiosk->id);
        DB::transaction(function () use ($kiosk, $cash) {
            BusinessAvailability::enforce($kiosk->business_id, lock: true);
            $locked = Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->active, 404);
            if (self::active($kiosk->id)) {
                throw ValidationException::withMessages(['shift' => 'This kiosk already has an open shift.']);
            }
            RegisterShift::create(['kiosk_id' => $kiosk->id, 'opened_by' => auth()->id(), 'opening_cash' => $cash, 'opened_at' => now()]);
        });
    }

    public static function totals(RegisterShift $shift): array
    {
        $cash = OrderPayment::where('register_shift_id', $shift->id)->where('method', 'cash')->sum('amount');
        $refunds = OrderPayment::where('refund_shift_id', $shift->id)->where('method', 'cash')->sum('amount');

        return ['cash' => (int) $cash, 'refunds' => (int) $refunds, 'expected' => (int) $shift->opening_cash + $cash - $refunds];
    }

    public static function close(Kiosk $kiosk, int $shiftId, array $counts, int $reviewedExpected): void
    {
        Access::authorize($kiosk->business_id, 'orders.pay', $kiosk->id);
        DB::transaction(function () use ($kiosk, $shiftId, $counts, $reviewedExpected) {
            BusinessAvailability::enforce($kiosk->business_id, lock: true);
            Kiosk::whereKey($kiosk->id)->lockForUpdate()->firstOrFail();
            $shift = self::active($kiosk->id);
            if (! $shift || $shift->id !== $shiftId) {
                throw ValidationException::withMessages(['shift' => 'The open shift changed. Review the current shift and try again.']);
            }
            $expected = self::totals($shift)['expected'];
            if ($expected !== $reviewedExpected) {
                throw ValidationException::withMessages(['shift' => 'The drawer balance changed. Review the updated amount before closing.']);
            }
            $shift->update([
                'closed_at' => now(), 'closed_by' => auth()->id(), 'expected_cash' => $expected,
                'counted_cash' => self::counted($counts), 'denominations' => $counts,
            ]);
        });
    }

    public static function counted(array $counts): int
    {
        $cash = 0;
        foreach ($counts as $denomination => $quantity) {
            // The ₱20 bill and ₱20 coin have distinct keys.
            $cash += (int) strtok((string) $denomination, '-') * (int) $quantity;
        }

        return $cash;
    }
}
