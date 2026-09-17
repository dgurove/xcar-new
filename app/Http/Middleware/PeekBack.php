<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Форма из окошка строки таблицы (фрейм peek): контроллер отвечает редиректом
 * как обычно — на страницу, back(), с flash и ошибками, — а сюда приходит
 * заголовок X-Peek-Back (ставит peek_controller) с адресом окошка, и редирект
 * уходит в него: Turbo рисует ответ во фрейме, flash и ошибки показывает окошко.
 * Только свой адрес и только не-GET: загрузка самого фрейма не трогается.
 */
class PeekBack
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $back = (string) $request->header('X-Peek-Back');
        if ($response instanceof RedirectResponse && ! $request->isMethod('GET') && $request->header('Turbo-Frame') === 'peek'
            && str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            $response->setTargetUrl($back);
        }

        return $response;
    }
}
