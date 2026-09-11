<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureStaff
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isStaff(), 404);

        return $next($request);
    }
}
