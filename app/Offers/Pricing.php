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
 *
 * Ступени — у предложения (07.11.2026, владелец: поставщик «регулирует диапазоны, меняет значения и добавляет новые, для
 * каждого предложения индивидуально»): `offers.floor_rules` → `vendors.floor_rules` → `RULES` у вендора с оценочной, иначе
 * `FLAT` (+0, закупочная = оценочная). Парковка считает по `RULES`, как прежде. Правило переоценки (`REBID`) — своё.
 */
final class Pricing
{
    /** Меньше — обрывок, а не цена машины (письмо, документ, разбор при заведении). */
    public const MIN = 10_000;

    /** Ступени наценки: [оценочная меньше, плюс рублей, плюс процентов]; последняя — без границы. */
    public const RULES = [[500_000, 50_000, 0], [2_000_000, 0, 10], [null, 200_000, 0]];

    /** Без наценки: закупочная равна оценочной (умолчание вендора без «Закупочная от оценочной»). */
    public const FLAT = [[null, 0, 0]];

    /** Больше ступеней не бывает: защита от мусора из формы. */
    public const MAX_STEPS = 12;

    /** Вверх до тысячи: 967 619 → 968 000. Округление до копеек сначала — 1.1 × 860 000 не даёт 946 000,0000001. */
    public static function ceilThousand(int|float $n): int
    {
        return (int) (ceil(round($n, 2) / 1000) * 1000);
    }

    /** Закупочная от оценочной по ступеням (без них — `RULES`). Без наценки — сама оценочная, без округления. */
    public static function floorFrom(int $value, ?array $rules = null): int
    {
        [, $add, $pct] = self::step($value, $rules ?? self::RULES);

        return $add || $pct ? self::ceilThousand($value + $add + $value * $pct / 100) : $value;
    }

    /**
     * Ступени предложения: свои → вендора → умолчание вендора. Вендор — моделью или id (id читаются раз на запрос).
     *
     * @return list<array{0: ?int, 1: int, 2: int|float}>
     */
    public static function rulesFor(Offer|Vendor|int|null $of): array
    {
        if ($of instanceof Offer) {
            return self::clean($of->floor_rules) ?? self::rulesFor($of->vendor_id);
        }
        if ($of instanceof Vendor) {
            return self::clean($of->floor_rules) ?? ($of->floor_by_value ? self::RULES : self::FLAT);
        }
        $vendor = $of ? (self::vendors()[$of] ?? null) : null;

        return self::clean($vendor['floor_rules'] ?? null) ?? (($vendor['floor_by_value'] ?? false) ? self::RULES : self::FLAT);
    }

    /**
     * Ступени, по которым закупочная идёт за оценочной в правке (`UpdateOffer`): свои у предложения или вендора с оценочной;
     * у остальных — прежнее общее правило `RULES` (до 07.11.2026 оно было у всех: правка оценочной модератором у
     * Т-Страхования давала ту же наценку, что у Альфы, — так и осталось). `FLAT` — только умолчание поставщика.
     */
    public static function autoRules(Offer $offer): array
    {
        return self::valued($offer) ? self::rulesFor($offer) : self::RULES;
    }

    /**
     * Закупочная у предложения считается от оценочной: вендор с оценочной (Альфа) или у предложения свои ступени —
     * их ставит поставщик на partner.xcar.ru (у него оценочная у всех вендоров).
     */
    public static function valued(Offer $offer): bool
    {
        return self::byValue($offer->vendor_id) || self::clean($offer->floor_rules) !== null;
    }

    /**
     * Ступени в порядок: строки [до, плюс ₽, плюс %], границы растут, последняя — без границы; мусор — null. Из формы
     * приходят строками с разрядами — `Money::rubles`.
     *
     * @return ?list<array{0: ?int, 1: int, 2: int|float}>
     */
    public static function clean(mixed $rules): ?array
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }
        if (! is_array($rules) || $rules === [] || count($rules) > self::MAX_STEPS) {
            return null;
        }
        $rows = [];
        foreach (array_values($rules) as $row) {
            if (! is_array($row) || count($row) < 3) {
                return null;
            }
            [$below, $add, $pct] = array_values($row);
            $below = $below === null || $below === '' ? null : Money::rubles($below);
            $add = Money::rubles($add ?? 0) ?? 0;
            $pct = is_numeric($pct = str_replace(',', '.', (string) ($pct ?? 0))) ? round((float) $pct, 2) : null;
            if ($pct === null || $pct < 0 || $pct > 100 || $add < 0 || ($below !== null && $below <= 0)) {
                return null;
            }
            $rows[] = [$below, $add, $pct == (int) $pct ? (int) $pct : $pct];
        }
        // Последняя — «дальше»: без границы, даже если границу вписали.
        $rows[array_key_last($rows)][0] = null;
        $edges = array_column(array_slice($rows, 0, -1), 0);
        if (in_array(null, $edges, true) || $edges !== array_values(array_unique($edges)) || $edges !== self::sorted($edges)) {
            return null;
        }

        return $rows;
    }

    /** Ступени словами строкой: «до 500 000 +50 000 ₽, до 2 000 000 +10%, дальше +200 000 ₽». */
    public static function rulesLabel(array $rules): string
    {
        return implode(', ', array_map(fn ($r) => ($r[0] === null ? (count($rules) > 1 ? 'дальше ' : '') : 'до '.Money::nums($r[0]).' ')
            .($r[2] ? '+'.str_replace('.', ',', (string) $r[2]).'%' : '+'.Money::rub($r[1])), $rules));
    }

    /**
     * Переоценка (04.11.2026, владелец; X6 № 2610041185, Альфа: «Максимальная ставка 5500000. К рассмотрению принимаются
     * предложения +5% или 50 000 руб. к максимальной ставке»): правило другое, чем при поступлении, — к новой оценочной
     * плюс 5 % или 50 000, что меньше (до миллиона — 5 %, дальше — 50 000). Ровно, без округления до тысячи (13.11.2026,
     * владелец: «прибавляет не 50 000, а 50 381» — 2 047 619 → 2 098 000 в письме Альфе): 2 047 619 → 2 097 619.
     */
    public const REBID = [50_000, 5];

    public static function rebid(int $value): int
    {
        return min(self::rebids($value));
    }

    /**
     * Оба варианта переоценки — чипами в шторке: «+50 000» и «+5%».
     *
     * @return array<string, int>
     */
    public static function rebids(int $value): array
    {
        [$add, $pct] = self::REBID;

        return ['+'.Money::nums($add) => $value + $add, "+{$pct}%" => (int) round($value * (100 + $pct) / 100)];
    }

    /** Наценка словами для письма страховой: «+50 000 ₽», «+10%». */
    public static function markup(int $value, ?array $rules = null): string
    {
        [, $add, $pct] = self::step($value, $rules ?? self::RULES);

        return $pct ? '+'.str_replace('.', ',', (string) $pct).'%' : '+'.Money::rub($add);
    }

    /**
     * Закупочная словами для письма страховой: «756 000 ₽ (+10%)» — наценка к оценочной по правилу. Вписанная рукой (не по
     * правилу) или без оценочной — без скобок.
     */
    public static function floorLabel(?int $floor, ?int $value, ?array $rules = null): string
    {
        if (! $floor) {
            return '';
        }
        $markup = $value && $floor !== $value && $floor === self::floorFrom($value, $rules) ? self::markup($value, $rules) : null;

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
    public static function floorFor(?int $floor, ?int $was, ?int $value, ?array $rules = null, ?array $wasRules = null): ?int
    {
        $rules ??= self::RULES;
        $wasRules ??= $rules;
        if (! $value || ($value === $was && $rules === $wasRules)) {
            return null;
        }
        $auto = $floor === null || ($was && $floor === self::floorFrom($was, $wasRules));

        return $auto ? self::floorFrom($value, $rules) : null;
    }

    /** Вендор, у которого сумма страховой — оценочная, а закупочная считается от неё. */
    public static function byValue(?int $vendorId): bool
    {
        return $vendorId !== null && in_array($vendorId, self::byValueIds(), true);
    }

    /** @return list<int> */
    public static function byValueIds(): array
    {
        return array_keys(array_filter(self::vendors(), fn ($v) => $v['floor_by_value']));
    }

    /** @return array<int, array{floor_by_value: bool, floor_rules: ?array}> */
    private static function vendors(): array
    {
        return once(fn () => Vendor::query()->get(['id', 'floor_by_value', 'floor_rules'])
            ->mapWithKeys(fn (Vendor $v) => [$v->id => ['floor_by_value' => (bool) $v->floor_by_value, 'floor_rules' => $v->floor_rules]])->all());
    }

    /** @param list<int> $edges */
    private static function sorted(array $edges): array
    {
        sort($edges);

        return $edges;
    }

    /**
     * Сумма от страховой в цены предложения: у вендора с оценочной — оценочная и закупочная от неё, иначе закупочная.
     *
     * @return array{value?: int, floor_price: int}
     */
    public static function fromAmount(?int $vendorId, int $amount, ?Offer $offer = null): array
    {
        return self::byValue($vendorId) ? ['value' => $amount, 'floor_price' => self::floorFrom($amount, self::rulesFor($offer ?? $vendorId))] : ['floor_price' => $amount];
    }

    /** @return array{0: ?int, 1: int, 2: int|float} */
    private static function step(int $value, array $rules): array
    {
        foreach ($rules as $rule) {
            if ($rule[0] === null || $value < $rule[0]) {
                return $rule;
            }
        }

        return $rules[array_key_last($rules)];
    }
}
