<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Dev-only gate. This dashboard exposes gp-cami data with no auth, so it
 * must never serve outside local/development environments.
 */
class DevOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment(['local', 'development'])) {
            abort(403, 'gp-cami dashboard is available in dev environments only.');
        }
        return $next($request);
    }
}
