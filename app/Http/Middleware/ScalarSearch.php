<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Поиск в списках — строка. `?q[]=x` (чужая ссылка, сканер) в `(string)` контроллеров и тулбара роняет страницу
 * ошибкой «Array to string conversion», поэтому не-строка в `q` и в чипах фильтров просто отбрасывается.
 */
class ScalarSearch
{
    public function handle(Request $request, Closure $next)
    {
        foreach (['q', 'preset', 'vendor', 'manager', 'city', 'category', 'yard', 'party', 'user', 'stage', 'kind'] as $key) {
            if (is_array($request->query->get($key))) {
                $request->query->remove($key);
            }
        }

        return $next($request);
    }
}
