<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Events\BidPlaced;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Подтверждение менеджера: для покупателя — с ценой не ниже порога, в гараж — без цены (там, где у предложения стоит
 * «Можно в гараж»). Живое подтверждение у человека одно: новое, любого вида, заменяет прежнее.
 */
final class PlaceBid
{
    public function __invoke(Offer $offer, User $by, ?int $amount, ?string $comment = null, BidKind $kind = BidKind::Buyer): Bid
    {
        if (! $by->canBid()) {
            throw ValidationException::withMessages(['amount' => 'Подтверждать могут только менеджеры']);
        }
        if (! $offer->isVisibleTo($by)) {
            throw ValidationException::withMessages(['amount' => 'Это предложение вам не открыто']);
        }

        return DB::transaction(function () use ($offer, $by, $amount, $comment, $kind) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $offer->bidsOpen()) {
                throw ValidationException::withMessages(['amount' => 'Приём подтверждений закрыт']);
            }
            if ($kind === BidKind::Garage) {
                if (! $offer->garageAllowedFor($by)) {
                    throw ValidationException::withMessages(['amount' => 'Эту машину в гараж не берут']);
                }
                $amount = null;
            } else {
                $min = $offer->minBid();
                if (! $amount) {
                    throw ValidationException::withMessages(['amount' => 'Впишите цену']);
                }
                if ($min && $amount < $min) {
                    throw ValidationException::withMessages(['amount' => 'Такую цену не принимаем — предложите выше']);
                }
            }

            $offer->bids()->where('user_id', $by->id)->where('state', BidState::Active)->update(['state' => BidState::Withdrawn]);

            $bid = $offer->bids()->create(['user_id' => $by->id, 'kind' => $kind, 'amount' => $amount, 'comment' => $comment, 'state' => BidState::Active]);
            $offer->log(OfferEventType::BidPlaced, $by, ['bid_id' => $bid->id, 'amount' => $amount, ...($kind === BidKind::Garage ? ['garage' => true] : [])]);
            BidPlaced::dispatch($bid, $by);

            return $bid;
        });
    }
}
