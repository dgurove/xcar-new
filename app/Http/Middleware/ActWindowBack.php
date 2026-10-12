<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Форма в окне действия (фрейм `act-frame`, `x-park.act-window`): ошибка проверки и `back()` ведут на страницу под окном
 * (Referer), и Turbo нашёл бы там пустой фрейм. Такой редирект уходит на шаг окна, откуда отправили (`X-Window`, ставит
 * `window_controller`): поля с ошибками остаются в окне. Шторки внутри шага («Покупатель», «Покупатель отказался»)
 * так же возвращаются в шаг.
 */
class ActWindowBack
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $response instanceof RedirectResponse || $request->isMethod('GET') || $request->header('Turbo-Frame') !== 'act-frame') {
            return $response;
        }
        $step = (string) $request->header('X-Window');
        $back = (string) $request->header('Referer');
        if (preg_match('#^/cars/\d+/window/[a-z]+(\?[\w=&%.-]*)?$#', $step) && $back !== '' && $response->getTargetUrl() === $back) {
            $response->setTargetUrl($step);
        }

        return $response;
    }
}
