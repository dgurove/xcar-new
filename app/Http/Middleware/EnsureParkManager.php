<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Правка вендоров, площадок, реквизитов ТС, отмена и удаление — не для «только приёмки». */
class EnsureParkManager
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->canManagePark(), 403);

        return $next($request);
    }
}
