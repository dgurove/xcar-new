<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Предзагрузка Turbo (наведение на ПК, касание на телефоне — `touch-prefetch.js`) приходит с `X-Sec-Purpose: prefetch`,
 * а Laravel узнаёт её только по `Sec-Purpose`/`Purpose`. Без перевода каждая предзагрузка запоминалась «предыдущей
 * страницей» сессии, и `back()` после формы уводил туда, куда человек лишь провёл пальцем (профиль → «Сделки»).
 */
class MarkPrefetch
{
    public function handle(Request $request, Closure $next)
    {
        if (strcasecmp((string) $request->headers->get('X-Sec-Purpose'), 'prefetch') === 0 && ! $request->headers->has('Sec-Purpose')) {
            $request->headers->set('Sec-Purpose', 'prefetch');
        }

        return $next($request);
    }
}
