<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\BusinessAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $surface = $request->route()?->getName() ?? $request->route()?->defaults['destination'] ?? null;
        $business = $request->route('business');

        if (! $business instanceof Business && $request->route('token') && in_array($surface, ['menu', 'menu.manifest', 'takeout', 'takeout.manifest', 'tracking'], true)) {
            $id = BusinessAvailability::customerBusinessId(explode('.', $surface)[0], $request->route('token'));
            $business = $id ? Business::find($id) : null;
        }

        if ($business instanceof Business && BusinessAvailability::paused($business)) {
            if ($request->is('business/*') && ! in_array($surface, ['pos', 'kitchen', 'payments'], true)) {
                $surface = 'workspace';
            }

            return BusinessAvailability::response($business, $surface, $request);
        }

        return $next($request);
    }
}
