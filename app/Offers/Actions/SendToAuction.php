<?php

namespace App\Offers\Actions;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «На аукцион» (13.10.2026). «Отправить на аукцион» — опубликованное снимается с продажи и сразу встаёт во вкладку
 * «На аукцион» (черновик — просто встаёт туда); «По истечению срока на аукцион» — план: часы (`due`, шаг `offers:tick`)
 * отправят его, когда приём закончится без единого подтверждения, а будут подтверждения — план снимут. Пока план стоит,
 * у предложения в CRM метка «На аукцион». Снять — «Отменить отправку на аукцион» / «Вернуть в черновики» (`cancel`); «Вернуть в продажу…» — `ReturnToSale`.
 */
final class SendToAuction
{
    public function __construct(private ChangeOfferState $change) {}

    public function now(Offer $offer, ?User $by, ?string $why = null): Offer
    {
        if (! in_array($offer->state, [OfferState::Open, OfferState::Draft, OfferState::Gallery], true) || $offer->onAuction()) {
            throw ValidationException::withMessages(['auction' => 'Это предложение на аукцион не отправить']);
        }
        if ($offer->state !== OfferState::Draft) {
            $offer = ($this->change)($offer, OfferState::Draft, $by);
        }
        $offer->forceFill(['auction_at' => now(), 'auction_planned_at' => null, 'auction_by' => $by?->id ?? $offer->auction_by])->save();
        $offer->log(OfferEventType::Auction, $by, array_filter(['to' => true, 'why' => $why]));

        return $offer;
    }

    public function plan(Offer $offer, User $by): Offer
    {
        if ($offer->state !== OfferState::Open || ! $offer->bidsOpen() || ! $offer->bids_close_at) {
            throw ValidationException::withMessages(['auction' => 'Приём подтверждений уже закончился']);
        }
        $offer->forceFill(['auction_planned_at' => now(), 'auction_by' => $by->id])->save();
        $offer->log(OfferEventType::Auction, $by, ['planned' => true]);

        return $offer;
    }

    public function cancel(Offer $offer, ?User $by, ?string $why = null): Offer
    {
        if ($offer->auction_planned_at) {
            $offer->forceFill(['auction_planned_at' => null])->save();
            $offer->log(OfferEventType::Auction, $by, array_filter(['unplanned' => true, 'why' => $why]));
        } elseif ($offer->auction_at) {
            $offer->forceFill(['auction_at' => null])->save();
            $offer->log(OfferEventType::Auction, $by, ['off' => true]);
        }

        return $offer;
    }

    /** Часы: приём закончился — без подтверждений на аукцион, с ними план снимается. */
    public function due(): int
    {
        $n = 0;
        $offers = Offer::where('state', OfferState::Open)->whereNotNull('auction_planned_at')
            ->whereNotNull('bids_close_at')->where('bids_close_at', '<=', now())->get();
        foreach ($offers as $offer) {
            $bids = $offer->bids()->where('state', BidState::Active)->exists();
            $bids ? $this->cancel($offer, null, 'bids') : $this->now($offer, null, 'no_bids');
            $n++;
        }

        return $n;
    }
}
