<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalSlugUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();
        if ($request->isMethod('GET') && $response->getStatusCode() === 200 && $route?->getName()) {
            foreach ($route->parameters() as $key => $value) {
                if ($value instanceof Model && is_string($route->originalParameter($key)) && $route->originalParameter($key) !== (string) $value->getRouteKey()) {
                    return redirect()->route($route->getName(), $route->parameters() + $request->query(), 301)->header('Cache-Control', 'private, no-store');
                }
            }
        }

return $response;
    }
}
