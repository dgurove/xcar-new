<?php

namespace App\Offers\Actions;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Оценочная неактуальна (15.11.2026, владелец по 0760/046/13173/26: «оценочная неактуальна, надо ждать цены от Альфы»): снять
 * оценочную продажи и закупочную, посчитанную от неё (вписанная рукой и цена продажи остаются) — черновик встаёт в «Без
 * закупочной». Одна дверь пункта «⋯» «Оценочная неактуальна» и Мигторга (`Valuations::drop`: лот появился позже оценочной).
 * `value_at` остаётся моментом снятия: старые суммы (заявка из писем, оценочная парковки) не встают (`Valuations::older`,
 * `ParkValue::follows`), новая — «Оценить из текста», бот, рука, свежая заявка — встаёт как обычно.
 */
final class DropValuation
{
    public function __construct(private UpdateOffer $update) {}

    /** Черновик с оценочной без слота, аукциона, сделки и живых подтверждений: опубликованное и сделки — переоценка. */
    public static function allows(Offer $offer): bool
    {
        return $offer->state === OfferState::Draft && $offer->value !== null && ! $offer->isScheduled() && ! $offer->onAuction()
            && ! $offer->costDeal() && ! $offer->bids()->whereIn('state', [BidState::Active, BidState::Accepted])->exists();
    }

    public function __invoke(Offer $offer, ?User $by, string $why): Offer
    {
        if (! self::allows($offer)) {
            throw ValidationException::withMessages(['value' => 'Оценочную сейчас не снять: машина не черновик или по ней есть подтверждения']);
        }
        $value = (int) $offer->value;
        $floor = $offer->floor_price ? (int) $offer->floor_price : null;
        // Закупочная от оценочной (по правилу предложения, вендора или ровно она) — уходит с ней; вписанная рукой остаётся.
        $auto = ! $floor || in_array($floor, [$value, Pricing::floorFrom($value), Pricing::floorFrom($value, Pricing::rulesFor($offer))], true);
        ($this->update)($offer, ['value' => null, 'value_from' => null] + ($auto ? ['floor_price' => null] : []), $by, ['why' => $why]);
        // Момент снятия — дата оценочной: что старше, не встанет.
        $offer->forceFill(['value_at' => now()])->saveQuietly();

        return $offer;
    }
}
