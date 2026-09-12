<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Снять оффер с ветки, пока по ней ничего не сделано: позиция стоит на
 * стартовом этапе и в журнале нет другого входа. Нужно против ошибочного
 * «Нужен вывоз» — иначе позиция висела бы просроченной до конца.
 */
final class DropRoute
{
    public function __invoke(Offer $offer, Track $track, ?User $by = null): Offer
    {
        return DB::transaction(function () use ($offer, $track, $by) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $position = Position::where('offer_id', $offer->id)->where('track', $track)->with('stage.workflow')->first();
            if (! $position) {
                return $offer;
            }
            $moved = $offer->events()->where('type', OfferEventType::StageEntered)
                ->where('payload->track', $track->value)->where('payload->to', '!=', $position->stage->name)->exists();
            if ($moved || $position->stage->isNot($position->stage->workflow->startStage())) {
                throw ValidationException::withMessages(['exit' => 'По этой ветке уже есть движение']);
            }
            $position->delete();
            $offer->unsetRelation('positions');
            if ($track === Track::Service && $offer->car_place) {
                $offer->update(['car_place' => null]);
            }
            $offer->log(OfferEventType::RouteDropped, $by, ['track' => $track->value]);

            return $offer;
        });
    }
}
