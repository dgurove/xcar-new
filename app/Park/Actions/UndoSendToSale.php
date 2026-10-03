<?php

namespace App\Park\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отправили по ошибке»: пока предложение — нетронутый черновик, связь снимается, а черновик, который завела
 * сама парковка, удаляется вместе со своими кадрами. Привязанное к уже существовавшему предложению только
 * отвязывается. Тронутое в CRM (правки, цена, подтверждения, публикация) парковка не отменяет — это решает CRM.
 */
final class UndoSendToSale
{
    public function __invoke(Vehicle $vehicle, User $by): void
    {
        DB::transaction(function () use ($vehicle, $by) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $offer = $vehicle->offer_id ? Offer::find($vehicle->offer_id) : null;
            if (! $offer || ! $vehicle->events()->where('type', EventType::SentToSale)->exists()) {
                throw ValidationException::withMessages(['vehicle' => 'ТС в продажу не отправляли']);
            }
            $mine = $offer->events()->where('type', OfferEventType::Created)->get()->contains(fn ($e) => ($e->payload['park_vehicle'] ?? null) === $vehicle->id);
            if ($mine && ! $this->untouched($offer)) {
                throw ValidationException::withMessages(['vehicle' => 'Предложение уже в работе в CRM, снимите его там']);
            }
            $vehicle->update(['offer_id' => null]);
            $vehicle->log(EventType::SaleWithdrawn, $by, ['number' => $offer->number]);
            if ($mine) {
                $offer->delete();
            } else {
                $offer->log(OfferEventType::Note, $by, ['text' => 'ТС с парковки отвязана']);
            }
        });
    }

    /** Черновик, в который никто не заходил: только создание и положение машины, ни цены, ни подтверждений, ни сделки. */
    private function untouched(Offer $offer): bool
    {
        return $offer->state === OfferState::Draft && $offer->published_at === null && $offer->asking_price === null
            && ! $offer->bids()->exists() && ! $offer->deal()->exists()
            && ! $offer->events()->whereNotIn('type', [OfferEventType::Created, OfferEventType::PlaceChanged])->exists();
    }
}
