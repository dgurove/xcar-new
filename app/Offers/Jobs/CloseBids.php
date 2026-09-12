<?php

namespace App\Offers\Jobs;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Закрыть приём подтверждений ровно в срок, а не на ближайшем минутном тике:
 * ставится с задержкой при каждой смене bids_close_at. Срок сдвинули — старая
 * джоба увидит другой срок и промолчит; offers:tick остаётся страховкой.
 */
final class CloseBids implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $offerId, public string $closeAt) {}

    public function handle(ChangeOfferState $changeState): void
    {
        $offer = Offer::find($this->offerId);
        if (! $offer || $offer->state !== OfferState::Open || ! $offer->bids_close_at) {
            return;
        }
        if ($offer->bids_close_at->toIso8601String() !== $this->closeAt || $offer->bids_close_at->isFuture()) {
            return;
        }
        $changeState($offer, OfferState::Closed, null);
    }
}
