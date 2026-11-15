<?php

namespace App\Offers;

use App\Cars\Car;
use App\Offers\Actions\UpdateOffer;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Оценочная продажи с парковки (15.11.2026, владелец: «из парковки с оценочной и закупочной — это работает корректно, НО
 * если они появились на Мигторге, та оценочная становится НЕАКТУАЛЬНОЙ»). У Альфы порядок такой: заявка на приём с
 * оценочной → черновик продажи сразу с закупочной от неё (`Park\Actions\AutoSale`) → лот на Мигторге → Альфа присылает
 * актуальную оценочную, её вставляют «Оценить из текста». Лот есть — оценочная с заявки продаже уже ложь.
 *
 * У продажи оценочная своя (`offers.value`, `Offer::carOwnFields`), у ТС парковки — `cars.value`: по ней ставка хранения
 * (`from_value`), её не трогаем. `offers.value_from = park` — пришла с парковки; любая другая правка оценочной (из
 * текста, бот, рукой, переоценка, поставщик) метку снимает (`Offer::booted`).
 *
 * - `inherit` — новая продажа машины, что стоит у нас и ещё не на Мигторге, берёт оценочную ТС;
 * - `follow` — правка оценочной на парковке идёт в продажу (`UpdateVehicle`), пока оценочная продажи с парковки и лота нет;
 * - `drop` / `sweep` — лот появился: оценочная с парковки и закупочная от неё по правилу снимаются, черновик уходит из
 *   «Без продажной цены» в «Без закупочной» (часы `migtorg:sync`, смена номера убытка — `UpdateOffer::identityChanged`).
 */
final class ParkValue
{
    public const PARK = 'park';

    public const WHY = 'Появилось на Мигторге — оценочная с парковки неактуальна';

    /** Пишет дверь парковки (`follow`): метка «с парковки» у оценочной остаётся (`Offer::booted`). */
    public static bool $writing = false;

    /** Машина с этим номером убытка есть на Мигторге (и ушедший с торгов лот считается: Альфа её уже выставляла). */
    public static function onMigtorg(?string $claimRefKey): bool
    {
        return $claimRefKey !== null && $claimRefKey !== '' && DB::table('migtorg_lots')->where('claim_ref_key', $claimRefKey)->exists();
    }

    /** Оценочная ТС годится продаже: она есть, а машины на Мигторге нет. */
    public static function usable(Car $car): bool
    {
        return (int) $car->value > 0 && ! self::onMigtorg($car->claim_ref_key);
    }

    /** Новая продажа — оценочная ТС парковки, если она ещё годится. */
    public static function inherit(Offer $offer): void
    {
        $car = $offer->carRecord();
        if ($offer->value || ! $car->exists || ! $car->vehicle || ! self::usable($car)) {
            return;
        }
        $offer->value = (int) $car->value;
        $offer->value_from = self::PARK;
    }

    /** Правка оценочной на парковке идёт в продажу: у продажи оценочной нет или она с парковки, а лота нет. */
    public static function follows(Offer $offer): bool
    {
        return ($offer->value === null || $offer->value_from === self::PARK) && ! self::onMigtorg($offer->claim_ref_key);
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

    /** Снять оценочную с парковки и закупочную, посчитанную от неё (вписанная рукой остаётся). Сняли — true. */
    public static function drop(Offer $offer): bool
    {
        if ($offer->value_from !== self::PARK || ! $offer->value || ! self::onMigtorg($offer->claim_ref_key) || $offer->costDeal()) {
            return false;
        }
        $value = (int) $offer->value;
        $floor = $offer->floor_price ? (int) $offer->floor_price : null;
        $auto = ! $floor || in_array($floor, [$value, Pricing::floorFrom($value), Pricing::floorFrom($value, Pricing::rulesFor($offer))], true);
        app(UpdateOffer::class)($offer, ['value' => null] + ($auto ? ['floor_price' => null] : []), null, ['why' => self::WHY]);

        return true;
    }

    /** Черновики без цены продажи и подтверждений, у которых оценочная с парковки, а машина уже на Мигторге. */
    public static function stale(): Builder
    {
        $keys = DB::table('migtorg_lots')->whereNotNull('claim_ref_key')->select('claim_ref_key');

        return Offer::query()->where('offers.value_from', self::PARK)->where('offers.value', '>', 0)
            ->where('offers.state', OfferState::Draft)->whereNull('offers.slot_at')
            ->where(fn ($p) => $p->whereNull('offers.asking_price')->orWhere('offers.asking_price', 0))
            ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted]))
            ->whereCar(fn ($c) => $c->whereIn('claim_ref_key', $keys));
    }

    /** Часы: снять у всех таких. Возвращает, у скольких сняли. */
    public static function sweep(): int
    {
        return self::stale()->get()->filter(fn (Offer $o) => self::drop($o))->count();
    }
}
