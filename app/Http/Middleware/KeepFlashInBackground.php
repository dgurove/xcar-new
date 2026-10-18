<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Фоновый запрос не съедает отметки сессии (18.10.2026: после «Принять» пропадали тост, уход строки, переход к следующей
 * карточке и окно письма). Flash живёт два запроса после формы, а сразу после неё браузер шлёт фоновые: значок
 * `/live/badges`, живые строки (`X-Live-Rows`), числа чипов (`X-Count`), предзагрузку соседней карточки, опросы скриптов
 * (`X-Requested-With`, ответ не страницей). Двух таких хватало, чтобы ответ самой формы пришёл без своих отметок. Здесь
 * фоновый запрос возвращает flash обратно (`reflash`) — его увидит первый настоящий переход.
 */
class KeepFlashInBackground
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->hasSession() && self::background($request)) {
            $request->session()->reflash();
        }

        return $response;
    }

    public static function background(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        return $request->prefetch() || $request->is('live/*')
            || $request->headers->has('X-Live-Rows') || $request->headers->has('X-Count')
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || (($accept = $request->headers->get('Accept')) !== null && $accept !== '' && ! str_contains($accept, 'text/html'));
    }
}
