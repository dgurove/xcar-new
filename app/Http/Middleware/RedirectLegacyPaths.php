<?php

namespace App\Http\Middleware;

use App\Support\Paths;
use Closure;
use Illuminate\Http\Request;

/**
 * Старые адреса транслитом (письма, уведомления в базе, Telegram, закладки) —
 * 301 на английские по словарю Support\Paths. Только GET/HEAD: формы старых
 * адресов не существуют, их отдаёт новая разметка.
 */
class RedirectLegacyPaths
{
    public function handle(Request $request, Closure $next)
    {
        // /admin/* переводит LegacyAdmin по своей таблице.
        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && ! str_starts_with($request->getPathInfo(), '/admin/')) {
            $new = Paths::translate($request->getPathInfo());
            if ($new !== null) {
                return redirect($new.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
            }
        }

        return $next($request);
    }
}
