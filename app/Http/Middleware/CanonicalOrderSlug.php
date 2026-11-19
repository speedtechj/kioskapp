<?php

namespace App\Http\Middleware;

use App\Models\Order;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalOrderSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->route('token');
        $order = Order::where(fn ($q) => $q->where('slug', $value)->orWhere('token', $value))->firstOrFail();
        if ($value !== $order->slug) {
            return redirect()->route('tracking', ['token' => $order->slug] + $request->query(), 301)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return $next($request);
    }
}
