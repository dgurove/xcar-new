<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Работа парковки (`User::canManagePark`) — и приёмщику; деньги — `ability:parkMoney`. */
class EnsureParkManager
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->canManagePark(), 403);

        return $next($request);
    }
}
