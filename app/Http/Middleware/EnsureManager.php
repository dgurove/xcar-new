<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Кабинет менеджера — покупатели, группы, показы: чужому 404, а не 403, чтобы не подсказывать, что есть. */
class EnsureManager
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isManager(), 404);

        return $next($request);
    }
}
