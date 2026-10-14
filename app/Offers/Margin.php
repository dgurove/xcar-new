<?php

namespace App\Offers;

/**
 * Наценка над закупочной — только для CRM (14.10.2026, владелец: после нового файла Carcade часть машин осталась на
 * сайте с закупочной выше продажной; «пометь проблемные, только в CRM»). Флага в базе нет: поправили цену — метка ушла.
 * `loss` — продажная не выше закупочной, `thin` — наценка меньше `THIN` закупочной.
 */
final class Margin
{
    public const THIN = 0.05;

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

    /** То же условие в SQL — чип «В минус» в списке предложений CRM. */
    public static function scope($query): void
    {
        $query->whereNotNull('offers.asking_price')->whereNotNull('offers.floor_price')
            ->whereRaw('offers.asking_price < offers.floor_price * '.(1 + self::THIN))
            ->whereIn('offers.state', [OfferState::Draft, OfferState::Gallery, OfferState::Open]);
    }
}
