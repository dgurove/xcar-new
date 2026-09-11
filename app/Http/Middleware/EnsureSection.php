<?php

namespace App\Http\Middleware;

use App\Users\Section;
use Closure;
use Illuminate\Http\Request;

/** Раздел (стоянка) открыт тем, кому он выдан; остальным — 404. */
class EnsureSection
{
    public function handle(Request $request, Closure $next, string $section)
    {
        abort_unless($request->user()?->canAccess(Section::from($section)), 404);

        return $next($request);
    }
}
