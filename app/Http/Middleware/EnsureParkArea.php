<?php

namespace App\Http\Middleware;

use App\Park\Area;
use Closure;
use Illuminate\Http\Request;

/**
 * Раздел парковки по галке управляющего (деньги, почта) — `park.area:money`; `park.area:admin` — настройки
 * (вендоры, прайс, парковки, реквизиты): только админу. Закрытое — 404, как чужой раздел.
 */
class EnsureParkArea
{
    public function handle(Request $request, Closure $next, string $area)
    {
        $user = $request->user();
        abort_unless($area === 'admin' ? $user?->isAdmin() : $user?->canPark(Area::from($area)), 404);

        return $next($request);
    }
}
