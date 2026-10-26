<?php

namespace App\Offers;

use App\Support\Money;
use App\Vendors\Vendor;

/**
 * Цены предложения из сумм — одна дверь (26.10.2026, приборка: правило закупочной жило в `Park\Sale`, двух JS-контроллерах
 * и `floorLabel`, округление до тысячи — в четырёх местах, порог «обрывок, а не цена» — в двух константах).
 *
 * Закупочная из оценочной (владелец, 04.10.2026): до 500 000 — плюс 50 000, до 2 000 000 — плюс 10 %, дальше плюс 200 000;
 * вверх до тысячи. Ступени — `RULES`, их же получает браузер (`data-floor-rules`, `lib/floor.js`), своей копии там нет.
 *
 * Сумма неизвестного рода (СМС, текст, лот Мигторга) — оценочная только у вендора с «Закупочная от оценочной»
 * (`vendors.floor_by_value`, Альфа), у остальных это сама закупочная — `fromAmount`.
 */
final class Pricing
{
    /** Меньше — обрывок, а не цена машины (письмо, документ, разбор при заведении). */
    public const MIN = 10_000;

    /** Ступени наценки: [оценочная меньше, плюс рублей, плюс процентов]; последняя — без границы. */
    public const RULES = [[500_000, 50_000, 0], [2_000_000, 0, 10], [null, 200_000, 0]];

    /** Вверх до тысячи: 967 619 → 968 000. Округление до копеек сначала — 1.1 × 860 000 не даёт 946 000,0000001. */
    public static function ceilThousand(int|float $n): int
    {
        return (int) (ceil(round($n, 2) / 1000) * 1000);
    }

    public static function floorFrom(int $value): int
    {
        [, $add, $pct] = self::step($value);

        return self::ceilThousand($value + $add + $value * $pct / 100);
    }

    /** Наценка словами для письма страховой: «+50 000 ₽», «+10%». */
    public static function markup(int $value): string
    {
        [, $add, $pct] = self::step($value);

        return $pct ? "+{$pct}%" : '+'.Money::rub($add);
    }

    /**
     * Закупочная словами для письма страховой: «756 000 ₽ (+10%)» — наценка к оценочной по правилу. Вписанная рукой (не по
     * правилу) или без оценочной — без скобок.
     */
    public static function floorLabel(?int $floor, ?int $value): string
    {
        if (! $floor) {
            return '';
        }
        $markup = $value && $floor === self::floorFrom($value) ? self::markup($value) : null;

        return Money::rub($floor).($markup ? " ({$markup})" : '');
    }

    /** Как получена закупочная (шторка «В продажу»): `rule` — по правилу от оценочной, `value` — равна ей, `own` — своя. */
    public static function floorMode(?int $floor, ?int $value): string
    {
        return match (true) {
            ! $value => 'own',
            ! $floor, $floor === self::floorFrom($value) => 'rule',
            $floor === $value => 'value',
            default => 'own',
        };
    }

    /**
     * Закупочная после смены оценочной: пустая или посчитанная от прежней оценочной — заново от новой; вписанная рукой
     * остаётся. null — трогать не нужно.
     */
    public static function floorFor(?int $floor, ?int $was, ?int $value): ?int
    {
        if (! $value || $value === $was) {
            return null;
        }
        $auto = $floor === null || ($was && $floor === self::floorFrom($was));

        return $auto ? self::floorFrom($value) : null;
    }

    /** Вендор, у которого сумма страховой — оценочная, а закупочная считается от неё. */
    public static function byValue(?int $vendorId): bool
    {
        return $vendorId !== null && in_array($vendorId, self::byValueIds(), true);
    }

    /** @return list<int> */
    public static function byValueIds(): array
    {
        return once(fn () => Vendor::where('floor_by_value', true)->pluck('id')->all());
    }

    /**
     * Сумма от страховой в цены предложения: у вендора с оценочной — оценочная и закупочная от неё, иначе закупочная.
     *
     * @return array{value?: int, floor_price: int}
     */
    public static function fromAmount(?int $vendorId, int $amount): array
    {
        return self::byValue($vendorId) ? ['value' => $amount, 'floor_price' => self::floorFrom($amount)] : ['floor_price' => $amount];
    }

    /** @return array{0: ?int, 1: int, 2: int} */
    private static function step(int $value): array
    {
        foreach (self::RULES as $rule) {
            if ($rule[0] === null || $value < $rule[0]) {
                return $rule;
            }
        }

        return self::RULES[array_key_last(self::RULES)];
    }
}
