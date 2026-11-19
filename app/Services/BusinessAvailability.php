<?php

namespace App\Services;

use App\Models\Business;
use App\Models\DiningTable;
use App\Models\Kiosk;
use App\Models\Order;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BusinessAvailability
{
    public const LABELS = [
        'live' => 'Live',
        'coming_soon' => 'Coming Soon',
        'maintenance' => 'Maintenance',
    ];

    public static function customerBusinessId(string $surface, string $token): ?int
    {
        return match ($surface) {
            'menu' => DiningTable::where('token', $token)->first()?->kiosk?->business_id,
            'takeout' => Kiosk::where('takeout_token', $token)->value('business_id'),
            'tracking' => Order::where(fn ($query) => $query->where('slug', $token)->orWhere('token', $token))->value('business_id'),
        };
    }

    public static function enforce(int $businessId, ?string $surface = null, bool $lock = false): void
    {
        $business = Business::query()->when($lock, fn ($query) => $query->sharedLock())->findOrFail($businessId);
        if (self::paused($business)) {
            throw new HttpResponseException(self::response($business, $surface, request()));
        }
    }

    public static function paused(Business $business): bool
    {
        return in_array($business->availability, ['coming_soon', 'maintenance'], true);
    }

    public static function response(Business $business, ?string $surface, Request $request): Response
    {
        $staffTitle = match ($surface) {
            'pos', 'cashier.sign-in', 'cashier.manifest' => 'Point of Sale paused',
            'kitchen' => 'Kitchen display paused',
            'payments' => 'Payments paused',
            'workspace' => 'Workspace paused',
            default => null,
        };
        $comingSoon = $business->availability === 'coming_soon';
        $title = $staffTitle ?? ($comingSoon ? 'We’re opening soon' : 'Temporarily unavailable');
        $message = match (true) {
            $staffTitle !== null => $comingSoon ? 'This business isn’t live yet.' : 'This business is under maintenance.',
            $comingSoon => 'This business hasn’t opened yet. Please check back later.',
            default => 'We’re doing some maintenance. Please check back shortly.',
        };
        $headers = [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
            'X-Gather-Business-Paused' => '1',
        ];
        $retry = ! $comingSoon && $request->isMethodSafe();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message, 'availability' => $business->availability], 503, $headers);
        }

        return response()->view('status.business-availability', compact('business', 'comingSoon', 'title', 'message', 'retry'), 503, $headers);
    }
}
