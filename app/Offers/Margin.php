<?php

namespace App\Offers;

use App\Support\Money;
use App\Support\Plural;

/**
 * Наценка над закупочной — только для CRM (14.10.2026, владелец: после нового файла Carcade часть машин осталась на
 * сайте с закупочной выше продажной; «пометь проблемные, только в CRM»). Флага в базе нет: поправили цену — метка ушла.
 * `loss` — продажная не выше закупочной, `thin` — наценка меньше `THIN` закупочной.
 * `check` — то же и опечатка вверх (`typo`, продажная от `TYPO` закупочных): переспрос «Точно?» при вводе цены и публикации
 * (`PriceGuard`, 02.11.2026: Lexus RX 2610142042 — закупочная 3 200 000, вписали продажу 1 200 000 и опубликовали).
 */
final class Margin
{
    public const THIN = 0.05;

    public const TYPO = 2;

    /** @return array{diff: int, tone: 'loss'|'thin'}|null */
    public static function of(?int $price, ?int $cost): ?array
    {
        if (! $price || ! $cost) {
            return null;
        }
        $diff = $price - $cost;

        return match (true) {
            $diff <= 0 => ['diff' => $diff, 'tone' => 'loss'],
            $diff < $cost * self::THIN => ['diff' => $diff, 'tone' => 'thin'],
            default => null,
        };
    }

    /**
     * Цена под вопросом — заголовок листа «Точно?» и строка «было → стало».
     *
     * @return array{tone: 'loss'|'thin'|'typo', title: string, text: string}|null
     */
    public static function check(?int $price, ?int $cost): ?array
    {
        if (! $price || ! $cost) {
            return null;
        }
        $line = 'Закупочная '.Money::rub($cost).' → продажа '.Money::rub($price);
        if ($price >= $cost * self::TYPO) {
            return ['tone' => 'typo', 'title' => 'Продажа в '.self::times($price / $cost).' выше закупочной — нет лишнего нуля?', 'text' => $line];
        }

        return match (self::of($price, $cost)['tone'] ?? null) {
            'loss' => ['tone' => 'loss', 'title' => $price === $cost ? 'Продать по закупочной, без наценки?' : 'Продать в минус на '.Money::rub($cost - $price).'?', 'text' => $line],
            'thin' => ['tone' => 'thin', 'title' => 'Наценка всего '.Money::rub($price - $cost).' ('.max(1, (int) floor(($price - $cost) * 100 / $cost)).' %)?', 'text' => $line],
            default => null,
        };
    }

    /** «2 раза», «2,5 раза», «12 раз». */
    private static function times(float $x): string
    {
        $n = $x < 10 ? round($x, 1) : round($x);

        return floor($n) == $n ? (int) $n.' '.Plural::of((int) $n, ['раз', 'раза', 'раз']) : str_replace('.', ',', (string) $n).' раза';
    }

    /** То же условие в SQL — чип «В минус» в списке предложений CRM. */
    public static function scope($query): void
    {
        $query->whereNotNull('offers.asking_price')->whereNotNull('offers.floor_price')
            ->whereRaw('offers.asking_price < offers.floor_price * '.(1 + self::THIN))
            ->whereIn('offers.state', [OfferState::Draft, OfferState::Gallery, OfferState::Open]);
    }
}
