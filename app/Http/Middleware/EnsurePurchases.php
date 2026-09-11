<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Совместные закупки видят только те роли, которым это положено. */
class EnsurePurchases
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->role->canSeePurchases(), 404);

        return $next($request);
    }
}
