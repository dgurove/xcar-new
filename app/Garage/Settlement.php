<?php

namespace App\Garage;

/**
 * Расклад по машине — одна логика на экран машины, список расчётов и счёт.
 *
 * вложено = отдали за + все расходы; прибыль = цена продажи − вложено;
 * вознаграждение менеджеру назначаем мы, остальное нам.
 * К оплате нам = цена продажи − расходы менеджера − вознаграждение: деньги покупателя
 * у него на руках, свои траты и вознаграждение он оставляет себе. Наши расходы в долг
 * не идут — они уменьшают только нашу прибыль.
 */
final class Settlement
{
    public static function of(Car $car): array
    {
        $mine = $car->spent(Payer::Manager);
        $ours = $car->spent(Payer::Xcar);
        $profit = $car->profit();
        $fee = (float) $car->commission;

        return [
            'manager_costs' => $mine,
            'our_costs' => $ours,
            'invested' => $car->invested(),
            'profit' => $profit,
            'fee' => $fee,
            'ours' => $profit === null ? null : round($profit - $fee, 2),
            'due' => $car->sold_price === null || ! $car->manager_id ? null : round($car->sold_price - $mine - $fee, 2),
        ];
    }
}
