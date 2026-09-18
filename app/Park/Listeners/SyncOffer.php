<?php

namespace App\Park\Listeners;

use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Events\TowScheduled;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleCancelled;
use App\Park\Events\VehicleDeparted;
use App\Park\Events\VehicleReleased;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\DropRoute;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;
use Illuminate\Validation\ValidationException;

/**
 * Правда о положении ТС — у стоянки; «где машина» на оффере и этап маршрута
 * вывоза — производные. Без связанного оффера слушатель молчит; без маршрута
 * вывоза двигает только car_place.
 */
final class SyncOffer
{
    public function __construct(private PlaceOnStage $place, private SetCarPlace $setPlace, private DropRoute $drop) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TowScheduled::class => 'scheduled',
            VehicleDeparted::class => 'departed',
            VehicleAccepted::class => 'accepted',
            VehicleReleased::class => 'released',
            VehicleCancelled::class => 'cancelled',
        ];
    }

    public function scheduled(TowScheduled $e): void
    {
        if (! ($offer = $this->offer($e->vehicle)) || $offer->car_place !== CarPlace::Owner) {
            return;
        }
        $position = $offer->position(Track::Service);
        $target = $position?->stage->workflow->stageForPlace(CarPlace::Moving, before: true);
        if ($position && $target && $position->stage_id !== $target->id && $position->stage->car_place === CarPlace::Owner) {
            ($this->place)($offer, $target, $e->by ?? $this->system());
        }
    }

    public function departed(VehicleDeparted $e): void
    {
        $this->moveTo($e->vehicle, CarPlace::Moving, $e->by);
    }

    public function accepted(VehicleAccepted $e): void
    {
        $this->moveTo($e->vehicle, CarPlace::Ours, $e->by);
    }

    public function released(VehicleReleased $e): void
    {
        if ($offer = $this->offer($e->vehicle)) {
            $offer->log(OfferEventType::Note, $e->by, ['text' => 'Выдана со стоянки'.($e->vehicle->yard ? ' «'.$e->vehicle->yard->name.'»' : '')]);
        }
    }

    public function cancelled(VehicleCancelled $e): void
    {
        if (! ($offer = $this->offer($e->vehicle))) {
            return;
        }
        try {
            ($this->drop)($offer, Track::Service, $e->by);
        } catch (ValidationException) {
            // По ветке уже есть движение — оставляем сотруднику.
        }
    }

    private function moveTo(Vehicle $vehicle, CarPlace $place, ?User $by): void
    {
        if (! ($offer = $this->offer($vehicle))) {
            return;
        }
        $position = $offer->position(Track::Service);
        $target = $position?->stage->workflow->stageForPlace($place);
        if ($position && $target && $position->stage_id !== $target->id) {
            ($this->place)($offer, $target, $by ?? $this->system());
        } else {
            ($this->setPlace)($offer, $place, $by);
        }
    }

    private function offer(Vehicle $vehicle): ?Offer
    {
        return $vehicle->offer_id ? Offer::with('positions.stage.workflow')->find($vehicle->offer_id) : null;
    }

    private function system(): User
    {
        return User::where('role', Role::Admin)->orderBy('id')->firstOrFail();
    }
}
