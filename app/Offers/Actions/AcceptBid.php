<?php

namespace App\Offers\Actions;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Принять подтверждение: сделка, остальные подтверждения отклонены, оффер в сделке.
 * Деньги фиксируются тут же: закупочная снимком, агентское вознаграждение и режим —
 * менеджер их не увидит, пока по сделке не выставлен счёт.
 */
final class AcceptBid
{
    public function __construct(private ChangeOfferState $changeState) {}

    public function __invoke(Bid $bid, User $by, ?int $commission = null, CommissionMode $mode = CommissionMode::Payout): Deal
    {
        return DB::transaction(function () use ($bid, $by, $commission, $mode) {
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

            $deal = Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $bid->user_id, 'amount' => $bid->amount, 'state' => DealState::Active,
                'cost' => $offer->floor_price, 'commission' => $commission, 'commission_mode' => $mode]);
            $offer->log(OfferEventType::BidAccepted, $by, ['bid_id' => $bid->id, 'amount' => $bid->amount, 'commission' => $commission, 'mode' => $mode->value]);
            ($this->changeState)($offer, OfferState::Sold, $by);
            BidAccepted::dispatch($bid, $by);

            return $deal;
        });
    }
}
