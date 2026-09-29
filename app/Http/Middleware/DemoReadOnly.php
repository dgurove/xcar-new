<?php

namespace App\Http\Middleware;

use App\Support\Demo\Demo;
use Closure;
use Illuminate\Http\Request;

/**
 * Демо-кабинет только смотрят: любая запись закрыта, кроме оплаты по счёту (шторка «Оплатить», отмена
 * ссылки, страница оплаты) и выхода. Подтвердить ценой, написать, завести покупателя или ссылку нельзя.
 */
class DemoReadOnly
{
    private const ALLOWED = ['logout', 'account/money/deals/*/pay', 'account/money/links/*', 'pay/*', 'push/subscription', 'account/notifications/*'];

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() && Demo::viewing() && ! $request->is(...self::ALLOWED)) {
            return back()->with('toast', 'В демонстрационном кабинете это недоступно');
        }

        return $next($request);
    }
}
