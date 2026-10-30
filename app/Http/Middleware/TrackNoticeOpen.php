<?php

namespace App\Http\Middleware;

use App\Notifications\Delivery;
use Closure;
use Illuminate\Http\Request;

/**
 * Перешли по пушу, кнопке Telegram или письму (`NoticeLink::tracked`, `?nt=<id>.<канал>`) — уведомление открыто
 * (`Delivery::opened`), а метка уходит из адреса тем же переходом: в истории и «Поделиться» её нет. Без входа метка
 * доезжает до страницы после входа (адрес запоминает вход), там и отмечается.
 */
class TrackNoticeOpen
{
    public function handle(Request $request, Closure $next)
    {
        $mark = $request->query('nt');
        if (! is_string($mark) || ! $request->isMethod('GET') || ! ($user = $request->user())) {
            return $next($request);
        }
        [$id, $via] = array_pad(explode('.', $mark, 2), 2, '');
        Delivery::opened($user, $id, $via);
        $query = $request->query();
        unset($query['nt']);

        return redirect($request->url().($query ? '?'.http_build_query($query) : ''));
    }
}
