<?php

namespace App\Http\Middleware;

use App\Notifications\ReadNotices;
use App\Users\Impersonation;
use Closure;
use Illuminate\Http\Request;

/**
 * Открыл сделку, чат, счёт — уведомления о нём прочитаны (ReadNotices). До ответа: счётчики в шапке этой же страницы
 * уже без них. Предзагрузка и «Войти как» ничего не отмечают — человек объекта не видел.
 */
class ReadNoticesOnVisit
{
    public function __construct(private ReadNotices $read) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $request->isMethod('GET') && ! $request->prefetch() && ! Impersonation::active()) {
            $path = '/'.ltrim($request->path(), '/');
            ($this->read)([$user->id], array_values(array_unique([$path, $request->getRequestUri()])));
        }

        return $next($request);
    }
}
