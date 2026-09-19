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
 * покупатель по базовому прайсу. Дни с одной ставкой и плательщиком
 * склеиваются в отрезки; выставленное (`storage_billed_until`) не повторяется.
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
        $end = $vehicle->released_at ? $vehicle->released_at->copy()->startOfDay() : Carbon::instance($until ?? now())->startOfDay();
        if ($from->gt($end)) {
            return collect();
        }

        $vendor = $vehicle->vendor;
        $payerRule = $vendor?->storage_payer ?? 'vendor';
        $buyerFrom = null;
        if ($vendor?->buyer_storage_after_days !== null && ($deal = $vehicle->offer?->deal) && $deal->buyer_id) {
            $buyerFrom = $deal->created_at->copy()->startOfDay()->addDays($vendor->buyer_storage_after_days);
        }

        $segments = collect();
        $current = null;
        for ($day = $from->copy(); $day->lte($end); $day->addDay()) {
            $index = (int) $first->diffInDays($day) + 1;
            $payer = $buyerFrom && $day->gte($buyerFrom) ? 'buyer' : $payerRule;
            $rate = $payer === 'nobody' ? 0.0 : self::rateOn($vehicle, $day, $index, $payer === 'buyer');
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

    /** Ставка на конкретный день: персональная, иначе лестница прайса (покупателю — базовый прайс), плюс негабарит. */
    private static function rateOn(Vehicle $vehicle, Carbon $day, int $index, bool $base): float
    {
        if ($vehicle->storage_rate !== null && ! $base) {
            $rate = (float) $vehicle->storage_rate;
        } else {
            // Прайс, заведённый позже приёма, действует и на прошлые невыставленные дни: цены обычно вносят задним числом.
            $ladder = Tariff::ladder($base ? null : $vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, TariffService::Storage, $day);
            if ($ladder->isEmpty() && $day->lt(now()->startOfDay())) {
                $ladder = Tariff::ladder($base ? null : $vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, TariffService::Storage);
            }
            $rate = (float) (Tariff::rateOnDay($ladder, $index) ?? 0);
        }
        if ($vehicle->oversize) {
            $extra = Tariff::ladder($base ? null : $vehicle->vendor_id, $vehicle->yard_id, $vehicle->category, TariffService::Oversize, $day);
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
