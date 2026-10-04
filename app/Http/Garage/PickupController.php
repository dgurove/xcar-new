<?php

namespace App\Http\Garage;

use App\Offers\Actions\PickUp;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Workflow\Actor;
use App\Workflow\Path;
use App\Workflow\Track;
use Illuminate\Http\Request;

/**
 * Вывоз, порученный менеджеру (не гараж: продаёт не обязательно он) — страница ТС с путём вывоза, датой, адресом и
 * контактом, и «Забрал», когда его ход. Список — группа «Вывоз» в «Гараже».
 */
class PickupController
{
    public function show(Request $request, Offer $offer)
    {
        // Забирает по своей сделке — всё о получении на странице сделки (ссылки «Пора забирать» вели сюда).
        if (($deal = $offer->deal) && $deal->buyer_id === $request->user()->id && $deal->buyerPicksUp()) {
            return redirect($deal->href());
        }
        $offer = $this->offer($request, $offer);
        $position = $offer->position(Track::Service);

        return view('garage.pickups.show', [
            'offer' => $offer,
            'position' => $position,
            'path' => Path::for($offer, Track::Service),
            'canPick' => (bool) $position?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty(),
            'photos' => $offer->visiblePhotos(),
            'docs' => OfferFiles::forManagers($offer, $request->user()),
        ]);
    }

    public function picked(Request $request, Offer $offer, PickUp $pick)
    {
        $pick($this->offer($request, $offer), $request->user());

        return back(fallback: '/garage')->with('toast', 'Забрали');
    }

    /** Вывоз этого менеджера или любой — сотруднику; чужой для менеджера не существует. */
    private function offer(Request $request, Offer $offer): Offer
    {
        abort_unless(Offer::pickupsOf($request->user())->whereKey($offer->id)->exists(), 404);

        return $offer->load(['brand', 'model', 'media', 'settlement', 'evacuator', 'positions.stage.block', 'positions.stage.exits.to', 'positions.stage.workflow']);
    }
}
