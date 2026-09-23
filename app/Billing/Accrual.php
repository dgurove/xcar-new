<?php

namespace App\Billing;

use App\Cars\Category;
use App\Park\Vehicle;
use App\Vendors\Tariff;
use App\Vendors\TariffService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Хранение на лету: сутки — календарный день, день приёма — первые сутки, день
 * выдачи тоже считается (решение владельца 19.09.2026), минимум одни. Каждый день оценивается по лестнице
 * прайса на ту дату (персональная ставка — поверх), негабарит прибавляется,
 * плательщик режет период: до `deal + N` дней — по правилу вендора, дальше —
 * покупатель по базовому прайсу. Площадка — та, где ТС стояла в этот день
 * (`Vehicle::yardTimeline`), сутки в пути между площадками не считаются
 * (день погрузки и день приёма — считаются). Дни с одной ставкой и
 * плательщиком склеиваются в отрезки; выставленное (`storage_billed_until`)
 * не повторяется.
 *
 * @phpstan-type Segment array{payer: string, from: Carbon, to: Carbon, days: int, rate: float, amount: float}
 */
final class Accrual
{
    /** @return Collection<int, Segment> */
    public static function storage(Vehicle $vehicle, ?CarbonInterface $until = null, bool $fromStart = false): Collection
    {
        if (! $vehicle->accepted_at) {
            return collect();
        }
        $vehicle->loadMissing(['vendor', 'offer.deal']);
        $first = $vehicle->accepted_at->copy()->startOfDay();
        $from = $vehicle->storage_billed_until && ! $fromStart ? $vehicle->storage_billed_until->copy()->addDay()->startOfDay() : $first->copy();
        // Конец периода — день выдачи, но не позже `until` (закрытие месяца считает по конец месяца).
        $end = Carbon::instance($until ?? $vehicle->released_at ?? now())->startOfDay();
        if ($vehicle->released_at && $vehicle->released_at->copy()->startOfDay()->lt($end)) {
            $end = $vehicle->released_at->copy()->startOfDay();
        }
        if ($from->gt($end)) {
            return collect();
        }

        $vendor = $vehicle->vendor;
        $payerRule = $vendor?->storage_payer ?? 'vendor';
        $buyerDay = self::buyerFrom($vehicle)?->toDateString();
        $multiplier = (float) ($vendor?->buyer_rate_multiplier ?? 3);

        // Дни идут строками «2026-09-23», а не объектами Carbon: у ТС бывает больше тысячи суток, и по
        // списку это десятки тысяч дней — Carbon на каждый день считал дольше, чем сам отбор по прайсу.
        // Полдень по UTC в опоре — чтобы шаг ровно в сутки не сбился ни в одном часовом поясе.
        $timeline = array_map(fn (array $e) => ['day' => $e['day']->toDateString(), 'yard_id' => $e['yard_id'], 'transit' => $e['transit'] ?? $e['yard_id'] === null], $vehicle->yardTimeline());
        $today = now()->toDateString();
        $endDay = $end->toDateString();
        $anchor = strtotime($from->toDateString().' 12:00:00 UTC');
        $index = (int) $first->diffInDays($from) + 1;
        $car = self::car($vehicle);
        $segments = collect();
        $current = null;
        for ($step = 0; ($day = gmdate('Y-m-d', $anchor + 86400 * $step)) <= $endDay; $step++, $index++) {
            $yard = self::yardOn($timeline, $day, $vehicle->yard_id);
            if ($yard === false) {
                continue;
            }
            $payer = $buyerDay && $day >= $buyerDay ? 'buyer' : $payerRule;
            $rate = $payer === 'nobody' ? 0.0 : (self::rateOn($car, $yard, $day, $index, false, $today) ?? 0.0);
            if ($payer === 'buyer') {
                // Покупатель платит по ставке вендора (нет — по базовому прайсу), умноженной на множитель вендора.
                $rate = ($rate ?: (self::rateOn($car, $yard, $day, $index, true, $today) ?? 0.0)) * $multiplier;
            }
            if ($current && $current['payer'] === $payer && abs($current['rate'] - $rate) < 0.005) {
                $current['to'] = $day;
                $current['days']++;
                $current['amount'] = round($current['days'] * $current['rate'], 2);

                continue;
            }
            if ($current) {
                $segments->push($current);
            }
            $current = ['payer' => $payer, 'from' => $day, 'to' => $day, 'days' => 1, 'rate' => $rate, 'amount' => round($rate, 2)];
        }
        if ($current) {
            $segments->push($current);
        }

        // Наружу отрезки отдаются датами: их считанные единицы, здесь Carbon уже ничего не стоит.
        return $segments->map(function (array $s) {
            $s['from'] = Carbon::parse($s['from']);
            $s['to'] = Carbon::parse($s['to']);

            return $s;
        });
    }

    /**
     * С какого дня хранение платит опоздавший покупатель: со следующего дня после срока, до которого оно за
     * счёт вендора (`buyer_free_until` — дата из письма страховой, её вписывают на ТС). Правило включается
     * галкой у вендора (`buyer_pays_late`): у Альфы и СОГАЗа покупатель не платит, у ВСК платит. Срок не
     * вписан — не начисляем (решение владельца: лишнего счёта человеку не выставляем).
     */
    public static function buyerFrom(Vehicle $vehicle): ?Carbon
    {
        $vehicle->loadMissing('vendor');
        if (! ($vehicle->vendor?->buyer_pays_late ?? false) || ! $vehicle->buyer_free_until) {
            return null;
        }

        return $vehicle->buyer_free_until->copy()->addDay()->startOfDay();
    }

    /** Ставка покупателя на сегодня — для чипа «покупатель с …, N ₽/сут». */
    public static function buyerRate(Vehicle $vehicle): float
    {
        $index = $vehicle->accepted_at ? (int) $vehicle->accepted_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : 1;
        $day = now()->toDateString();
        $car = self::car($vehicle);
        $rate = self::rateOn($car, $vehicle->yard_id, $day, $index, false) ?: (self::rateOn($car, $vehicle->yard_id, $day, $index, true) ?? 0.0);

        return $rate * (float) ($vehicle->vendor?->buyer_rate_multiplier ?? 3);
    }

    /**
     * Площадка на день по ленте: последняя запись не позже дня; погрузка в этот же день — ещё на прежней
     * площадке, раньше — ТС в пути (false). Без ленты — нынешняя площадка.
     *
     * @param  list<array{day: string, yard_id: ?int, transit: bool}>  $timeline
     */
    private static function yardOn(array $timeline, string $day, ?int $current): int|null|false
    {
        $yard = $current;
        $previous = $current;
        foreach ($timeline as $e) {
            if ($e['day'] > $day) {
                break;
            }
            if ($e['transit']) {
                $yard = $e['day'] === $day ? $previous : false;
            } else {
                // Принята без парковки — стоит, площадка неизвестна: ставка по прайсу без площадки.
                $previous = $yard = $e['yard_id'];
            }
        }

        return $yard;
    }

    /**
     * Что из ТС нужно ставке — простыми значениями: цикл по дням читал бы поля модели десятки тысяч раз.
     *
     * @return array{vendor_id: ?int, category: ?Category, value: ?int, oversize: bool, storage_rate: ?float}
     */
    private static function car(Vehicle $vehicle): array
    {
        return ['vendor_id' => $vehicle->vendor_id, 'category' => $vehicle->category, 'value' => $vehicle->value,
            'oversize' => (bool) $vehicle->oversize, 'storage_rate' => $vehicle->storage_rate === null ? null : (float) $vehicle->storage_rate];
    }

    /**
     * Ставка на конкретный день: персональная, иначе лестница прайса (покупателю — базовый прайс), плюс негабарит.
     *
     * @param  array{vendor_id: ?int, category: ?Category, value: ?int, oversize: bool, storage_rate: ?float}  $car
     */
    private static function rateOn(array $car, ?int $yard, string $day, int $index, bool $base, ?string $today = null): ?float
    {
        $today ??= now()->toDateString();
        $vendorId = $base ? null : $car['vendor_id'];
        if ($car['storage_rate'] !== null && ! $base) {
            $rate = $car['storage_rate'];
        } else {
            // Прайс, заведённый позже приёма, действует и на прошлые невыставленные дни: цены обычно вносят задним числом.
            $steps = Tariff::stepsOn($vendorId, $yard, $car['category'], TariffService::Storage, $day, $car['value']);
            if (! $steps && $day < $today) {
                $steps = Tariff::stepsOn($vendorId, $yard, $car['category'], TariffService::Storage, $today, $car['value']);
            }
            $found = self::stepRate($steps, $index);
            if ($found === null) {
                // Прайса на эту ТС нет: считать нечем, а не бесплатно (в списке — тег «Нет тарифа»).
                return null;
            }
            $rate = $found;
        }
        if ($car['oversize']) {
            $extra = Tariff::stepsOn($vendorId, $yard, $car['category'], TariffService::Oversize, $day, $car['value']);
            $rate += self::stepRate($extra, $index) ?? 0.0;
        }

        return $rate;
    }

    /**
     * Ставка на N-е сутки по ступеням: последняя, чей `from_day` не больше N.
     *
     * @param  list<array{0: int, 1: float}>  $steps
     */
    private static function stepRate(array $steps, int $index): ?float
    {
        $rate = null;
        foreach ($steps as [$fromDay, $price]) {
            if ($fromDay <= $index) {
                $rate = $price;
            }
        }

        return $rate;
    }

    /** Есть ли чем считать сутки: персональная ставка или лестница прайса. Пилюля «Без ставки» и тег причины. */
    public static function hasRate(Vehicle $vehicle): bool
    {
        return $vehicle->storage_rate !== null || Tariff::ladderFor($vehicle, TariffService::Storage)->isNotEmpty();
    }

    /**
     * Ставка на сегодня (у выданной — на день выдачи) по нынешнему плательщику: чип и столбец «₽/сут».
     * null — прайса на эту ТС нет: не заполнена категория, не заполнена стоимость при тарифе по стоимости
     * или у вендора нет прайса вовсе.
     */
    public static function rateToday(Vehicle $vehicle): ?float
    {
        if (! $vehicle->accepted_at) {
            return null;
        }
        $vehicle->loadMissing(['vendor', 'offer.deal']);
        $first = $vehicle->accepted_at->copy()->startOfDay();
        $day = $vehicle->released_at && $vehicle->released_at->startOfDay()->lt(now()->startOfDay()) ? $vehicle->released_at->copy()->startOfDay() : now()->startOfDay();
        if ($day->lt($first)) {
            $day = $first->copy();
        }
        $buyerFrom = self::buyerFrom($vehicle);
        $payer = $buyerFrom && $day->gte($buyerFrom) ? 'buyer' : ($vehicle->vendor?->storage_payer ?? 'vendor');
        if ($payer === 'nobody') {
            return 0.0;
        }
        $index = (int) $first->diffInDays($day) + 1;
        $on = $day->toDateString();
        $car = self::car($vehicle);
        if ($payer !== 'buyer') {
            return self::rateOn($car, $vehicle->yard_id, $on, $index, false);
        }
        $rate = self::rateOn($car, $vehicle->yard_id, $on, $index, false) ?: self::rateOn($car, $vehicle->yard_id, $on, $index, true);

        return $rate === null ? null : $rate * (float) ($vehicle->vendor?->buyer_rate_multiplier ?? 3);
    }

    /**
     * Ставка и набежавшее за всё время по многим ТС разом — столбцы списка «Наличия». Считается от дня
     * приёма (`fromStart`), а не от выставленного: владельцу нужна вся сумма, что набила машина.
     *
     * @param  iterable<Vehicle>  $vehicles
     * @return array<int, array{rate: ?float, days: int, amount: float}>
     */
    public static function totals(iterable $vehicles): array
    {
        $out = [];
        foreach ($vehicles as $v) {
            $segments = self::storage($v, null, true);
            $out[$v->id] = ['rate' => self::rateToday($v), 'days' => (int) $segments->sum('days'), 'amount' => round((float) $segments->sum('amount'), 2)];
        }

        return $out;
    }

    /** Сколько начислено и не выставлено — по плательщикам, для чипов. @return array<string, array{days: int, amount: float}> */
    public static function summary(Vehicle $vehicle): array
    {
        $out = [];
        foreach (self::storage($vehicle) as $s) {
            $out[$s['payer']] ??= ['days' => 0, 'amount' => 0.0];
            $out[$s['payer']]['days'] += $s['days'];
            $out[$s['payer']]['amount'] += $s['amount'];
        }

        return $out;
    }

    public static function payerLabel(string $payer): string
    {
        return match ($payer) {
            'vendor' => 'вендор', 'owner' => 'страхователь', 'buyer' => 'покупатель', default => 'никто'
        };
    }
}
