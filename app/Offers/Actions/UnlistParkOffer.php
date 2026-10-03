<?php

namespace App\Offers\Actions;

use App\Mail\Candidate;
use App\Mail\Thread;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Снять ТС парковки с продажи: черновик, который завели зря, — отвязываем ТС
 * (в её истории «Снята с продажи») и письма, сам черновик удаляем. Только черновик,
 * ни разу не выходивший наружу, без сделки и живых подтверждений.
 */
final class UnlistParkOffer
{
    public function __invoke(Offer $offer, User $by): void
    {
        abort_unless($offer->state === OfferState::Draft && $offer->published_at === null
            && $offer->parkVehicle()->exists()
            && ! $offer->deal()->exists()
            && ! $offer->bids()->where('state', BidState::Active)->exists(), 404);

        DB::transaction(function () use ($offer, $by) {
            foreach (Vehicle::where('offer_id', $offer->id)->get() as $vehicle) {
                $vehicle->update(['offer_id' => null]);
                $vehicle->log(EventType::Unlinked, $by, ['number' => $offer->number]);
            }
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            Candidate::where('offer_id', $offer->id)->update(['offer_id' => null]);
            $offer->delete();
        });
    }
}
