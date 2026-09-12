<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Принять подтверждение: сделка, остальные подтверждения отклонены, оффер в сделке. */
final class AcceptBid
{
    public function __construct(private ChangeOfferState $changeState) {}

    public function __invoke(Bid $bid, User $by): Deal
    {
        return DB::transaction(function () use ($bid, $by) {
            $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
            if ($bid->state !== BidState::Active) {
                throw ValidationException::withMessages(['bid' => 'Подтверждение уже '.mb_strtolower($bid->state->label())]);
            }
            $offer = $bid->offer;

            $bid->update(['state' => BidState::Accepted, 'decided_at' => now(), 'decided_by' => $by->id]);
            $offer->bids()->where('state', BidState::Active)->get()->each(function (Bid $other) use ($by) {
                $other->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by->id]);
                BidDeclined::dispatch($other, $by);
            });

            $deal = Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $bid->user_id, 'amount' => $bid->amount, 'state' => DealState::Active]);
            $offer->log(OfferEventType::BidAccepted, $by, ['bid_id' => $bid->id, 'amount' => $bid->amount]);
            ($this->changeState)($offer, OfferState::Sold, $by);
            BidAccepted::dispatch($bid, $by);

            return $deal;
        });
    }
}
