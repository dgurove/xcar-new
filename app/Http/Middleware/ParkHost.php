<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** На хосте стоянки живут только её маршруты и вход: витрина и админ оттуда не отвечают. */
class ParkHost
{
    private const SHARED = ['vhod', 'vyhod', 'registraciya', 'parol', 'passkey', 'up', 'media', 'live', 'chaty', 'manifest.webmanifest', 'offline', 'push', 'sw.js'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->getHost() === config('xcar.park_host')) {
            $route = $request->route();
            $own = $route?->getDomain() === config('xcar.park_host');
            $shared = in_array(explode('/', $request->path())[0], self::SHARED, true);
            abort_unless($own || $shared, 404);
        }

        return $next($request);
    }

    public static function isPark(Request $request): bool
    {
        return $request->getHost() === config('xcar.park_host');
    }
}
