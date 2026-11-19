<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StaffPins
{
    public const RULE = 'regex:/^[0-9]{6}$/D';

    public static function staff(Business $business, Kiosk $kiosk, array $roles, bool $configured = false): Collection
    {
        $query = $business->users()->wherePivotIn('role', $roles)->where('users.is_super_admin', false);
        if ($configured) {
            $query->wherePivotNotNull('pos_pin_hash');
        }

        return $query->orderBy('users.name')->get()->filter(fn (User $user) => Access::assignedKiosks($user, $business->id)->contains('id', $kiosk->id))->values();
    }

    public static function canManage(User $user, Business $business, Kiosk $kiosk): bool
    {
        return in_array(Access::membership($user, $business->id)?->pivot->role, ['owner', 'admin', 'manager'], true)
            && Access::kiosks($user, $business->id)->contains('id', $kiosk->id);
    }

    public static function hashFor(int $businessId, int $userId): ?string
    {
        return DB::table('business_user')->where('business_id', $businessId)->where('user_id', $userId)->value('pos_pin_hash');
    }

    public static function verify(int $businessId, int $userId, string $pin, string $field = 'pin'): string
    {
        $key = 'pos-pin:'.$businessId.':'.$userId;
        $ipKey = 'pos-pin-ip:'.$businessId.':'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            throw ValidationException::withMessages([$field => 'Too many PIN attempts. Try again in five minutes.']);
        }
        RateLimiter::hit($key, 300);
        RateLimiter::hit($ipKey, 300);

        $hash = self::hashFor($businessId, $userId);
        if (! $hash || ! preg_match('/^[0-9]{6}$/D', $pin) || ! Hash::check(self::secret($pin), $hash)) {
            throw ValidationException::withMessages([$field => 'The PIN is incorrect.']);
        }
        RateLimiter::clear($key);

        return $hash;
    }

    public static function approval(Kiosk $kiosk, int $managerId, string $pin): array
    {
        validator(['managerId' => $managerId, 'pin' => $pin], ['managerId' => 'required|integer|min:1', 'pin' => ['required', self::RULE]])->validate();
        $manager = self::staff($kiosk->business, $kiosk, ['owner', 'admin', 'manager'], true)->firstWhere('id', $managerId);
        if (! $manager) {
            throw ValidationException::withMessages(['pin' => 'Choose a manager assigned to this kiosk.']);
        }
        self::verify($kiosk->business_id, $managerId, $pin);

        return ['manager_id' => $managerId, 'staff_id' => auth()->id(), 'approved_at' => now()->toIso8601String()];
    }

    public static function save(int $businessId, int $userId, string $pin, ?string $previousHash = null): string
    {
        $hash = Hash::make(self::secret($pin));
        $query = DB::table('business_user')->where('business_id', $businessId)->where('user_id', $userId);
        if ($previousHash !== null) {
            $query->where('pos_pin_hash', $previousHash);
        }
        if ($query->update(['pos_pin_hash' => $hash]) !== 1) {
            throw ValidationException::withMessages(['pin' => 'The PIN changed. Please try again.']);
        }

        return $hash;
    }

    private static function secret(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }
}
