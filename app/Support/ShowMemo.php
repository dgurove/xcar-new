<?php

namespace App\Support;

use Closure;

/**
 * Прочитанное на показ — раз на запрос (17.11.2026, окно сделки: журнал пути, блоки маршрута и этапы читались по три-пять раз
 * разными кусками вида). Только GET в вебе: в действиях маршрут меняется посреди запроса, в очереди и консоли процесс живёт
 * долго — там каждый раз заново.
 */
final class ShowMemo
{
    public static function get(string $key, Closure $compute): mixed
    {
        if (app()->runningInConsole() || ! request()->isMethodSafe()) {
            return $compute();
        }

        return StaffCache::perRequest('show:'.$key, $compute);
    }
}
