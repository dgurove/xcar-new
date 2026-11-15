<?php

namespace App\Offers;

use App\Cars\Car;
use App\Offers\Actions\UpdateOffer;
use App\Users\User;

/**
 * Оценочная продажи с парковки. У продажи оценочная своя (`offers.value`, `Offer::carOwnFields`), у ТС парковки — `cars.value`:
 * по ней ставка хранения. `offers.value_from = park` — пришла с парковки; любая другая правка оценочной метку снимает
 * (`Offer::booted`). Какая из оценочных верна — решает дата (`Valuations`, 15.11.2026: «мы оценки с парковки отметали и
 * опирались на оценку от Альфы, но это был неверный подход»), Мигторг сам по себе ничего не отменяет.
 *
 * - `inherit` — новая продажа машины, что стоит у нас, берёт оценочную ТС с её датой;
 * - `follow` — правка оценочной на парковке идёт в продажу (`UpdateVehicle`), пока оценочная продажи с парковки или её нет;
 * - `confirm` — поставщик прислал ту же сумму («уже так» в «Оценить из текста» и у бота): оценочная подтверждена.
 */
final class ParkValue
{
    public const PARK = 'park';

    /** Пишет дверь парковки (`follow`): метка «с парковки» у оценочной остаётся (`Offer::booted`). */
    public static bool $writing = false;

    /** Оценочная ТС годится продаже: она есть. */
    public static function usable(Car $car): bool
    {
        return (int) $car->value > 0;
    }

    /** Новая продажа — оценочная ТС парковки. */
    public static function inherit(Offer $offer): void
    {
        $car = $offer->carRecord();
        if ($offer->value || ! $car->exists || ! $car->vehicle || ! self::usable($car)) {
            return;
        }
        $offer->value = (int) $car->value;
        $offer->value_at = $car->value_at;
        $offer->value_from = self::PARK;
    }

    /** Правка оценочной на парковке идёт в продажу: у продажи оценочной нет или она с парковки. */
    public static function follows(Offer $offer): bool
    {
        return $offer->value === null || $offer->value_from === self::PARK;
    }

    /** Новая оценочная ТС — в продажу, если она туда идёт (`follows`); закупочная — за ней, если не вписана рукой. */
    public static function follow(Offer $offer, int $value, ?User $by): void
    {
        if ($offer->costDeal() || ! self::follows($offer)) {
            return;
        }
        self::$writing = true;
        try {
            app(UpdateOffer::class)($offer, ['value' => $value, 'value_from' => self::PARK], $by, ['why' => 'Оценочная на парковке']);
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Сумма поставщика («Оценить из текста», бот) совпала с оценочной с парковки — оценочная подтверждена: метка «с
     * парковки» снимается, в «Истории» — «Оценка из текста: оценочная N». Только уверенные строки (`same`, `fill`): спор
     * сумм, другой VIN или номер с другим годом — нет.
     *
     * @param  list<array<string, mixed>>  $items  строки `MatchValuations`
     */
    public static function confirm(array $items, User $by): void
    {
        foreach ($items as $i) {
            $offer = $i['offer'] ?? null;
            if (! $offer || $i['direct'] || ! in_array($i['status'], ['same', 'fill'], true) || $offer->value_from !== self::PARK
                || (int) $offer->value !== (int) $i['amount']) {
                continue;
            }
            $offer->forceFill(['value_from' => null])->saveQuietly();
            $offer->log(OfferEventType::Updated, $by, ['source' => 'valuation', 'value' => (int) $i['amount'], 'why' => 'Поставщик подтвердил оценочную']);
        }
    }
}
