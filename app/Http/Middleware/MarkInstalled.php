<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Установленное приложение: start_url манифеста — список раздела с «?app=1» (старые установки — «/?app=1»), по нему ставится
 * cookie app на год и адрес чистится. Дальше сервер знает, что это приложение с
 * экрана «Домой»: без первого экрана, подвала, крошек и подсказки установки.
 */
class MarkInstalled
{
    public const COOKIE = 'app';

    public static function installed(Request $request): bool
    {
        return $request->cookie(self::COOKIE) === '1' || $request->attributes->get('app.installed') === true;
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->query('app') === '1' && $request->isMethod('GET')) {
            Cookie::queue(self::COOKIE, '1', 60 * 24 * 365, '/', config('session.domain'), $request->isSecure(), true, false, 'lax');
            $query = array_diff_key($request->query(), ['app' => 1]);
            // У корня своей страницы нет — на дом хоста, как прежде.
            if ($request->path() === '/') {
                return redirect(Surface::current()->home());
            }
            // Без редиректа (18.11.2026): адрес запуска из манифеста (`/offers?app=1`) отвечал 302 на каждом запуске с
            // иконки — лишний круг до сервера, и воркер не мог отдать такой экран из кэша (ответ с редиректом навигации
            // не годится), приложение каждый раз ждало сеть. Страница рисуется сразу, как без метки; из адреса её
            // убирает клиент (`app.js`, `cleanLaunchAddress`).
            $request->attributes->set('app.installed', true);
            $request->query->remove('app');
            $request->server->set('QUERY_STRING', http_build_query($query));
        }

        return $next($request);
    }
}
