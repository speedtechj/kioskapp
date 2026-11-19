<?php

namespace App\Http\Middleware;

use App\Models\Kiosk;
use App\Services\Access;
use App\Services\StaffPins;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LimitPinSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $request->session()->get('pos_pin_scope');
        if (! $scope || $request->routeIs('logout', 'cashier.manifest')) {
            return $next($request);
        }

        $user = $request->user();
        $kiosk = Kiosk::find($scope['kiosk']);
        $hash = $user ? StaffPins::hashFor($scope['business'], $user->id) : null;
        if (! $user || $user->is_super_admin || ! $hash || ! hash_equals($scope['version'], hash('sha256', $hash))
            || ! $kiosk?->active || $kiosk->business_id !== $scope['business']
            || Access::membership($user, $scope['business'])?->pivot->role !== 'cashier'
            || ! Access::assignedKiosks($user, $scope['business'])->contains('id', $scope['kiosk'])) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($request->routeIs('home', 'cashier.sign-in')) {
            return redirect()->route('pos', [$kiosk->business, $kiosk]);
        }
        if ($request->routeIs('*.update') && $request->is('livewire-*/update')) {
            $components = $request->input('components');
            abort_unless(is_array($components), 403);
            foreach ($components as $component) {
                abort_unless(is_array($component) && is_string($component['snapshot'] ?? null), 403);
                $snapshot = json_decode($component['snapshot'], true);
                abort_unless(in_array($snapshot['memo']['name'] ?? null, ['terminal', 'payment-desk', 'workspace', 'kitchen'], true), 403);
            }

            return $next($request);
        }

        $asset = $request->isMethod('GET') && preg_match('#^livewire-[a-f0-9]+/(livewire\.js|(?:css|js)/[^/]+)$#D', $request->path());
        $orders = $request->routeIs('workspace') && $request->query('tab') === 'orders';
        abort_unless($asset || $orders || $request->routeIs('pos', 'payments', 'kitchen', 'cashier.pin.edit', 'cashier.pin.update'), 403);

        return $next($request);
    }
}
