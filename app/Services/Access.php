<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Support\Collection;

class Access
{
    public static function membership(User $user, int $businessId): ?Business
    {
        return $user->businesses()->where('businesses.id', $businessId)->first();
    }

    public static function allows(User $user, int $businessId, string $permission): bool
    {
        if ($scope = session()->get('pos_pin_scope')) {
            if ($scope['business'] !== $businessId || ! in_array($permission, ['pos.use', 'orders.pay', 'orders.view', 'orders.update', 'kitchen.use'], true)) {
                return false;
            }
        }
        if ($permission === 'promotions.manage') {
            return in_array(self::membership($user, $businessId)?->pivot->role, ['owner', 'admin', 'manager'], true);
        }
        if ($user->is_super_admin) {
            return true;
        }
        $business = self::membership($user, $businessId);
        if (! $business) {
            return false;
        }
        if ($permission === 'menu.manage') {
            return in_array($business->pivot->role, ['manager', 'owner'], true);
        }
        $base = config('kiosk.roles.'.$business->pivot->role, []);
        $extra = json_decode($business->pivot->permissions ?? '[]', true) ?? [];

        return in_array('*', $base, true) || in_array($permission, array_merge($base, $extra), true);
    }

    public static function authorize(int $businessId, string $permission, ?int $kioskId = null): void
    {
        $user = auth()->user();
        abort_unless($user && self::allows($user, $businessId, $permission), 403);
        if ($kioskId !== null) {
            abort_unless(self::kiosks($user, $businessId)->contains('id', $kioskId), 403);
        }
    }

    public static function kiosks(User $user, int $businessId): Collection
    {
        $kiosks = self::assignedKiosks($user, $businessId);
        $scope = session()->get('pos_pin_scope');

        return $scope ? $kiosks->where('id', $scope['kiosk'])->where('active', true) : $kiosks;
    }

    public static function assignedKiosks(User $user, int $businessId): Collection
    {
        $q = Kiosk::where('business_id', $businessId);
        $role = self::membership($user, $businessId)?->pivot->role;
        if (! $user->is_super_admin && ! in_array($role, ['owner', 'admin'], true)) {
            $q->whereHas('business.users', fn ($q) => $q->where('users.id', $user->id));
            $q->whereIn('id', $user->kiosks()->select('kiosks.id'));
        }

        return $q->get();
    }
}
