<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Общие дорогие расчёты сотрудников — одна дверь (17.11.2026, «почему сервер так долго отдаёт»): раскладка «в какой вкладке
 * машина» (`OfferTabs`, `DealTabs`), числа колеса вкладок (`OfferController::counts`) и счётчики навигации (`Nav`).
 * Прежде у каждого был свой `Cache::remember` на 30 с и общий сброс `Nav::forgetStaffCounts` на любое событие сотрудникам
 * или парковке: кэш в Postgres читался по 15 раз за страницу, жил секунды, а после сброса каждый открытый клиент пересчитывал
 * одно и то же сам — десятки выборок по всем предложениям параллельно на двух ядрах.
 *
 * - Чтение — раз на запрос (значение лежит в атрибутах запроса; в консоли и очереди — без памяти).
 * - Сброс — не удалением ключей, а меткой времени группы (`bump`): значение свежее, если посчитано после метки и моложе
 *   срока. Две группы: `TABS` — раскладка вкладок и числа колеса, `COUNTS` — счётчики навигации. Событие про чат, почту,
 *   оплату или парковку бьёт только `COUNTS` (`Publisher`), событие про предложения и прямой `forgetStaffCounts` — обе.
 * - Пересчёт — один на всех (замок): кто пришёл вторым, ждёт результат первого, а не считает то же рядом. Не дождался за
 *   `WAIT` секунд — считает сам, без замка: медленный сосед не роняет страницу.
 * - Значение хранится дольше срока свежести — устаревшее никому не отдаётся, только место в таблице кэша.
 */
final class StaffCache
{
    public const TABS = 'tabs';

    public const COUNTS = 'counts';

    private const WAIT = 4;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @param  ?string  $group  метка сброса; null — только срок
     * @return T
     */
    public static function remember(string $key, int $seconds, Closure $compute, ?string $group = self::TABS): mixed
    {
        return self::perRequest('staff-cache:'.$key, function () use ($key, $seconds, $compute, $group) {
            if (($hit = self::fresh($key, $seconds, $group)) !== null) {
                return $hit['v'];
            }
            $lock = Cache::lock('staff-cache-lock:'.$key, 30);
            if (! $lock->get()) {
                try {
                    $lock->block(self::WAIT);
                } catch (LockTimeoutException) {
                    return self::store($key, $seconds, $compute);
                }
                // Пока ждали, сосед посчитал — берём его.
                self::forgetStamp($group);
                if (($hit = self::fresh($key, $seconds, $group)) !== null) {
                    $lock->release();

                    return $hit['v'];
                }
            }
            try {
                return self::store($key, $seconds, $compute);
            } finally {
                $lock->release();
            }
        });
    }

    /** Расчёты группы устарели: посчитанное до этого момента больше не отдаётся. */
    public static function bump(string $group): void
    {
        Cache::forever('staff-cache-stamp:'.$group, microtime(true));
        self::forgetStamp($group);
        // Что уже прочитано в этом запросе, посчитано до правки — читается заново.
        if (! app()->runningInConsole()) {
            foreach (request()->attributes->keys() as $name) {
                if (str_starts_with($name, 'staff-cache:')) {
                    request()->attributes->remove($name);
                }
            }
        }
    }

    /** @return ?array{v: mixed, at: float} значение, посчитанное после сброса группы и моложе срока */
    private static function fresh(string $key, int $seconds, ?string $group): ?array
    {
        $hit = Cache::get('staff-cache:'.$key);
        if (! is_array($hit) || ! array_key_exists('v', $hit) || microtime(true) - $hit['at'] > $seconds) {
            return null;
        }

        return $group !== null && $hit['at'] <= self::stamp($group) ? null : $hit;
    }

    private static function store(string $key, int $seconds, Closure $compute): mixed
    {
        // Время — начала расчёта: правка, случившаяся, пока считали, делает значение устаревшим.
        $at = microtime(true);
        $value = $compute();
        Cache::put('staff-cache:'.$key, ['v' => $value, 'at' => $at], max($seconds * 2, 120));

        return $value;
    }

    private static function stamp(string $group): float
    {
        return (float) self::perRequest('staff-cache-stamp:'.$group, fn () => Cache::get('staff-cache-stamp:'.$group, 0));
    }

    private static function forgetStamp(?string $group): void
    {
        if ($group !== null && ! app()->runningInConsole()) {
            request()->attributes->remove('staff-cache-stamp:'.$group);
        }
    }

    private static function perRequest(string $key, Closure $compute): mixed
    {
        $memo = app()->runningInConsole() ? null : request()->attributes;
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $value = $compute();
        $memo?->set($key, $value);

        return $value;
    }
}
