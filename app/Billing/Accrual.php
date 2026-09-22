<?php

namespace App\Billing;

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
    public static function storage(Vehicle $vehicle, ?CarbonInterface $until = null): Collection
    {
        if (! $vehicle->accepted_at) {
            return collect();
        }
        $vehicle->loadMissing(['vendor', 'offer.deal']);
        $first = $vehicle->accepted_at->copy()->startOfDay();
        $from = $vehicle->storage_billed_until ? $vehicle->storage_billed_until->copy()->addDay()->startOfDay() : $first->copy();
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
        $buyerFrom = self::buyerFrom($vehicle);
        $multiplier = (float) ($vendor?->buyer_rate_multiplier ?? 3);

        $timeline = $vehicle->yardTimeline();
        $segments = collect();
        $current = null;
        for ($day = $from->copy(); $day->lte($end); $day->addDay()) {
            $yard = self::yardOn($timeline, $day, $vehicle->yard_id);
            if ($yard === false) {
                continue;
            }
            $index = (int) $first->diffInDays($day) + 1;
            $payer = $buyerFrom && $day->gte($buyerFrom) ? 'buyer' : $payerRule;
            $rate = $payer === 'nobody' ? 0.0 : self::rateOn($vehicle, $yard, $day, $index, false);
            if ($payer === 'buyer') {
                // Покупатель платит по ставке вендора (нет — по базовому прайсу), умноженной на множитель вендора.
                $rate = ($rate ?: self::rateOn($vehicle, $yard, $day, $index, true)) * $multiplier;
            }
            if ($current && $current['payer'] === $payer && abs($current['rate'] - $rate) < 0.005) {
                $current['to'] = $day->copy();
                $current['days']++;
                $current['amount'] = round($current['days'] * $current['rate'], 2);

                continue;
            }
            if ($current) {
                $segments->push($current);
            }
            $current = ['payer' => $payer, 'from' => $day->copy(), 'to' => $day->copy(), 'days' => 1, 'rate' => $rate, 'amount' => round($rate, 2)];
        }
        if ($current) {
            $segments->push($current);
        }

        return $segments;
    }

    /**
     * С какого дня хранение платит покупатель: продажа (письмо страховой или сделка CRM) плюс дни за счёт вендора.
     * Без даты продажи или без правила у вендора — никогда.
     */
    public static function buyerFrom(Vehicle $vehicle): ?Carbon
    {
        $vehicle->loadMissing(['vendor', 'offer.deal']);
        $days = $vehicle->vendor?->buyer_storage_after_days;
        if ($days === null) {
            return null;
        }
        $sold = $vehicle->sold_at?->copy() ?? (($deal = $vehicle->offer?->deal) && $deal->buyer_id ? $deal->created_at->copy() : null);

        return $sold?->startOfDay()->addDays($days);
    }

    /** Ставка покупателя на сегодня — для чипа «покупатель с …, N ₽/сут». */
    public static function buyerRate(Vehicle $vehicle): float
    {
        $index = $vehicle->accepted_at ? (int) $vehicle->accepted_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : 1;
        $rate = self::rateOn($vehicle, $vehicle->yard_id, now(), $index, false) ?: self::rateOn($vehicle, $vehicle->yard_id, now(), $index, true);

        return $rate * (float) ($vehicle->vendor?->buyer_rate_multiplier ?? 3);
    }

    /**
     * Площадка на день по ленте: последняя запись не позже дня; погрузка в этот же день — ещё на прежней
     * площадке, раньше — ТС в пути (false). Без ленты — нынешняя площадка.
     *
     * @param  list<array{day: Carbon, yard_id: ?int, transit?: bool}>  $timeline
     */
    private static function yardOn(array $timeline, Carbon $day, ?int $current): int|null|false
    {
        $yard = $current;
        $previous = $current;
        foreach ($timeline as $e) {
            if ($e['day']->gt($day)) {
                break;
            }
            if ($e['transit'] ?? $e['yard_id'] === null) {
                $yard = $e['day']->eq($day) ? $previous : false;
            } else {
                // Принята без парковки — стоит, площадка неизвестна: ставка по прайсу без площадки.
                $previous = $yard = $e['yard_id'];
            }
        }

        return $yard;
    }

    /** Ставка на конкретный день: персональная, иначе лестница прайса (покупателю — базовый прайс), плюс негабарит. */
    private static function rateOn(Vehicle $vehicle, ?int $yard, Carbon $day, int $index, bool $base): float
    {
        if ($vehicle->storage_rate !== null && ! $base) {
            $rate = (float) $vehicle->storage_rate;
        } else {
            // Прайс, заведённый позже приёма, действует и на прошлые невыставленные дни: цены обычно вносят задним числом.
            $ladder = Tariff::ladder($base ? null : $vehicle->vendor_id, $yard, $vehicle->category, TariffService::Storage, $day);
            if ($ladder->isEmpty() && $day->lt(now()->startOfDay())) {
                $ladder = Tariff::ladder($base ? null : $vehicle->vendor_id, $yard, $vehicle->category, TariffService::Storage);
            }
            $rate = (float) (Tariff::rateOnDay($ladder, $index) ?? 0);
        }
        if ($vehicle->oversize) {
            $extra = Tariff::ladder($base ? null : $vehicle->vendor_id, $yard, $vehicle->category, TariffService::Oversize, $day);
            $rate += (float) (Tariff::rateOnDay($extra, $index) ?? 0);
        }

        return $rate;
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
