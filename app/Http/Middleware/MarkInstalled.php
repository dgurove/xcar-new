<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Установленное приложение: start_url манифеста — «/?app=1», по нему ставится
 * cookie app на год и адрес чистится. Дальше сервер знает, что это приложение с
 * экрана «Домой»: без первого экрана, подвала, крошек и подсказки установки.
 */
class MarkInstalled
{
    public const COOKIE = 'app';

    public static function installed(Request $request): bool
    {
        return $request->cookie(self::COOKIE) === '1';
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->query('app') === '1' && $request->isMethod('GET')) {
            Cookie::queue(self::COOKIE, '1', 60 * 24 * 365, '/', config('session.domain'), $request->isSecure(), true, false, 'lax');
            $query = array_diff_key($request->query(), ['app' => 1]);

            return redirect($request->path() === '/' ? '/' : '/'.$request->path().($query ? '?'.http_build_query($query) : ''));
        }

        return $next($request);
    }
}
