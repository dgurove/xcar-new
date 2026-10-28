<?php

namespace App\Offers\Actions;

use App\Mail\Actions\LinkThread;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Message;
use App\Notifications\RepriceNotice;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Reprice;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\Notification;

/**
 * Переоценка на решение (28.10.2026, владелец: «по такому-то письму такая-то переоценка, с возможностью принять или
 * отменить»). Письмо поставщика ровно об этой машине («Новая стоимость – 433 200 руб.», `Template::newPrice`) по
 * предложению с закупочной заводит ждущую переоценку — сама закупочная не меняется; админам `RepriceNotice`. Тем же
 * `open` пользуется `UpdateOffer`, когда новую закупочную вписали при уже подтвердивших.
 */
final class DetectReprice
{
    /** Состояния, где закупочная ещё что-то решает: черновик, продажа, сделка, гараж. */
    private const LIVE = [OfferState::Draft, OfferState::Gallery, OfferState::Open, OfferState::Sold, OfferState::Garage];

    public function __invoke(Offer $offer, Message $message): ?Reprice
    {
        $price = self::priceIn($offer, $message);

        return $price ? self::open($offer, $price, 'letter', $message) : null;
    }

    /** Новая цена из письма по этой машине, если оно переоценивает её закупочную (и по нему переоценки ещё не было). */
    public static function priceIn(Offer $offer, Message $message): ?int
    {
        if (! $offer->floor_price || ! in_array($offer->state, self::LIVE, true)
            || ! $message->isFromVendor() || ! FillFromLetter::about($offer, $message) || LinkThread::otherCar($offer, $message)
            || Reprice::where('message_id', $message->id)->exists()) {
            return null;
        }
        $price = Template::newPrice($message->ownText());

        return $price && $price !== (int) $offer->floor_price ? $price : null;
    }

    /**
     * Завести ждущую переоценку: та же цена уже ждёт — она и есть; прежняя ждущая с другой ценой уходит (`superseded`).
     * Цена равна нынешней закупочной — переоценивать нечего.
     */
    public static function open(Offer $offer, int $price, string $source, ?Message $message = null, ?User $by = null): ?Reprice
    {
        if ($price === (int) $offer->floor_price) {
            return null;
        }
        $pending = $offer->pendingReprice()->first();
        if ($pending && $pending->to_price === $price) {
            return $pending;
        }
        $pending?->update(['state' => Reprice::SUPERSEDED]);
        $reprice = $offer->reprices()->create(['from_price' => $offer->floor_price, 'to_price' => $price, 'source' => $source,
            'message_id' => $message?->id, 'created_by' => $by?->id, 'state' => Reprice::PENDING]);
        $reprice->setRelation('offer', $offer)->setRelation('message', $message);
        $offer->log(OfferEventType::Reprice, $by, ['reprice' => $reprice->id, 'state' => Reprice::PENDING, 'from' => $reprice->from_price,
            'to' => $price, 'source' => $source, ...($message ? ['letter' => $message->id] : [])]);
        // Вписал сам — шторка открывается у него же; письмо и прочие двери — админам.
        if (! $by) {
            Notification::send(User::withRole(Role::Admin)->get(), new RepriceNotice($reprice));
        }

        return $reprice;
    }
}
