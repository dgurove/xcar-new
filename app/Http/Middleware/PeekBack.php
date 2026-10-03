<?php

namespace App\Http\Middleware;

use App\Support\Detail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Форма из карточки строки рядом со списком (фрейм detail, App\Support\Detail): контроллер отвечает редиректом как
 * обычно — на страницу, back(), с flash и ошибками, — а редирект уходит на адрес, откуда пришли, то есть на список с
 * ?peek=, и тот отвечает фреймом: Turbo рисует его на месте карточки, flash и ошибки показывает она же. Контроллеры про
 * карточку не знают. Только свой адрес и только не-GET: загрузка самого фрейма не трогается.
 */
class PeekBack
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $response instanceof RedirectResponse || $request->isMethod('GET') || $request->header('Turbo-Frame') !== Detail::FRAME) {
            return $response;
        }
        $back = parse_url((string) $request->header('Referer'));
        if (($back['host'] ?? null) === $request->getHost() && isset($back['path']) && str_contains($back['query'] ?? '', 'peek=')) {
            $response->setTargetUrl($back['path'].'?'.$back['query']);
        }

        return $response;
    }
}
