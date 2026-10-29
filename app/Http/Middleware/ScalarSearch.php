<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Поиск в списках — строка. `?q[]=x` (чужая ссылка, сканер) в `(string)` контроллеров и тулбара роняет страницу
 * ошибкой «Array to string conversion», поэтому не-строка в `q` и в чипах фильтров просто отбрасывается. Так же — вкладки,
 * вид, сортировка, страница, окошко строки, месяц и ящик (29.10.2026: `?pill[]=x`, `?box[]=x`, `?month[]=x`, `?car[]=x`
 * роняли вендора, почту, закрытие месяца и новую заявку парковки 500-й; найдено обходом всех GET-адресов).
 */
class ScalarSearch
{
    private const KEYS = ['q', 'preset', 'vendor', 'manager', 'city', 'category', 'yard', 'party', 'user', 'stage', 'kind',
        'pill', 'box', 'tab', 'account', 'sort', 'vid', 'view', 'per', 'page', 'peek', 'mode', 'month', 'day', 'car'];

    public function handle(Request $request, Closure $next)
    {
        // Не `query->get()`: на массиве он сам бросает «non-scalar value» (400) — отбросить было бы нечего.
        $query = $request->query->all();
        foreach (self::KEYS as $key) {
            if (is_array($query[$key] ?? null)) {
                $request->query->remove($key);
            }
        }

        return $next($request);
    }
}
