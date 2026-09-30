<?php

namespace App\Http\Middleware;

use App\Support\Paths;
use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;

/**
 * Старые адреса — 301 на нынешние:
 * - хост гаража `garage.<сайт>` (жил отдельно до 30.09.2026) → `<сайт>/garage/…`;
 * - переехавшие разделы (`/account/deals` → `/deals`, `Paths::MOVED`) — любым методом:
 *   открытая до выкладки страница шлёт формы на прежний адрес;
 * - транслит (письма, уведомления в базе, Telegram, закладки) по словарю Support\Paths —
 *   только GET/HEAD: формы транслитных адресов не существуют.
 */
class RedirectLegacyPaths
{
    public function handle(Request $request, Closure $next)
    {
        $read = $request->isMethod('GET') || $request->isMethod('HEAD');
        $query = $request->getQueryString() ? '?'.$request->getQueryString() : '';
        $status = $read ? 301 : 308;

        if ($request->getHost() === 'garage.'.Surface::Site->host()) {
            // Экраны гаража — под /garage, общее (вход, медиа, уведомления) — тем же путём на сайте.
            $path = rtrim($request->getPathInfo(), '/');
            $own = $path === '' || preg_match('#^/(cars|costs|money)(/|$)#', $path);

            return redirect(Surface::Site->url(($own ? '/garage' : '').$path).$query, $status);
        }

        // /admin/* переводит LegacyAdmin по своей таблице.
        if (str_starts_with($request->getPathInfo(), '/admin/')) {
            return $next($request);
        }

        $new = $read ? Paths::translate($request->getPathInfo()) : Paths::moved($request->getPathInfo());
        if ($new !== null) {
            return redirect($new.$query, $status);
        }

        return $next($request);
    }
}
