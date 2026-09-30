<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Раздел по способности человека: `ability:canGarage` зовёт `User::canGarage()`. Чужому 404, а не 403 —
 * не подсказывать, что раздел есть. Новому разделу — метод у `User`/`Role`, а не ещё один класс middleware.
 */
class EnsureAbility
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        abort_unless((bool) $request->user()?->{$ability}(), 404);

        return $next($request);
    }
}
