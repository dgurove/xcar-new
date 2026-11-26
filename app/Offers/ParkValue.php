<?php

namespace App\Offers;

use App\Cars\Car;
use App\Offers\Actions\UpdateOffer;
use App\Users\User;

/**
 * Оценочная продажи с парковки. У продажи оценочная своя (`offers.value`, `Offer::carOwnFields`), у ТС парковки — `cars.value`:
 * по ней ставка хранения. `offers.value_from = park` — пришла с парковки; любая другая правка оценочной метку снимает
 * (`Offer::booted`). Какая из оценочных верна — решает дата (`Valuations`, 15.11.2026: «мы оценки с парковки отметали и
 * опирались на оценку от Альфы, но это был неверный подход»); лот на Мигторге, появившийся позже оценочной ТС, делает её
 * неактуальной продаже («появление на Мигторге произошло ПОСЛЕ заявки на приём, а значит та оценка уже не актуальна»).
 *
 * - `inherit` — новая продажа машины, что стоит у нас, берёт оценочную ТС с её датой, если лот не появился позже;
 * - `follow` — правка оценочной на парковке идёт в продажу (`UpdateVehicle`), пока оценочная продажи с парковки или её нет и
 *   машины нет на Мигторге (на парковке вписывают сумму с заявки на приём, а торги её уже обесценили);
 * - `confirm` — поставщик прислал ту же сумму («уже так» в «Оценить из текста» и у бота): оценочная подтверждена.
 */
final class ParkValue
{
    public const PARK = 'park';

    /** Пишет дверь парковки (`follow`): метка «с парковки» у оценочной остаётся (`Offer::booted`). */
    public static bool $writing = false;

    /** Оценочная ТС годится продаже: она есть, и лот на Мигторге не появился позже неё. */
    public static function usable(Car $car): bool
    {
        return (int) $car->value > 0 && ! Valuations::beforeLot($car->claim_ref_key, Valuations::carAt($car));
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

    /**
     * Черновик, что был до заявки на приём (`Park\Actions\AutoSale`, 26.11.2026): у него нет оценочной — берёт оценочную ТС с её
     * датой, если лот на Мигторге не появился позже неё (`usable`) и её не снимали как неактуальную; закупочная — за ней
     * (`UpdateOffer`, если не вписана рукой).
     */
    public static function adopt(Offer $offer): void
    {
        $car = $offer->carRecord();
        $dropped = $offer->value === null && $offer->value_at !== null;
        if ($dropped || $offer->costDeal()) {
            return;
        }
        // Оценочная уже стоит (взяла при заведении, `inherit`), а закупочной нет — закупочная от неё по правилу.
        if ($offer->value && ! $offer->floor_price) {
            Pricing::valued($offer) && app(UpdateOffer::class)($offer, ['floor_price' => Pricing::floorFrom((int) $offer->value, Pricing::rulesFor($offer))], null, ['why' => 'Закупочная от оценочной']);

            return;
        }
        if ($offer->value || ! $car->exists || ! self::usable($car)) {
            return;
        }
        self::$writing = true;
        try {
            app(UpdateOffer::class)($offer, ['value' => (int) $car->value, 'value_from' => self::PARK], null, ['why' => 'Оценочная с парковки', 'as_of' => $car->value_at]);
        } finally {
            self::$writing = false;
        }
    }

    /** Правка оценочной на парковке идёт в продажу: у продажи оценочной нет или она с парковки, а машины нет на Мигторге. */
    public static function follows(Offer $offer): bool
    {
        // Сняли как неактуальную (`DropValuation`: оценочной нет, дата снятия есть) — ждём новую от поставщика, парковка не вписывает.
        $dropped = $offer->value === null && $offer->value_at !== null;

        return ! $dropped && ($offer->value === null || $offer->value_from === self::PARK) && ! Valuations::lotAt($offer->claim_ref_key);
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
