<?php

namespace App\Park\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\CarPlace;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Events\TowScheduled;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleCancelled;
use App\Park\Events\VehicleDeparted;
use App\Park\Events\VehicleReleased;
use App\Park\Events\VehicleRestored;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\DropRoute;
use App\Workflow\Actions\EnsureServiceWorkflow;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
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
    public function __construct(private PlaceOnStage $place, private SetCarPlace $setPlace, private DropRoute $drop, private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TowScheduled::class => 'scheduled',
            VehicleDeparted::class => 'departed',
            VehicleAccepted::class => 'accepted',
            VehicleReleased::class => 'released',
            VehicleCancelled::class => 'cancelled',
            VehicleRestored::class => 'restored',
        ];
    }

    public function scheduled(TowScheduled $e): void
    {
        if (! ($offer = $this->offer($e->vehicle)) || $offer->car_place !== CarPlace::Owner || ! $this->toYard($offer)) {
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

    /** Приём отменён или ТС снова ждём: у владельца. */
    public function restored(VehicleRestored $e): void
    {
        $this->moveTo($e->vehicle, CarPlace::Owner, $e->by);
    }

    /**
     * Выдали с парковки: если продажа ждёт передачи машины (своя машина, Stock), шаг «Автомобиль передан» делается сам.
     * Гаражную машину выдали её менеджеру (без менеджера — нам): вывоз встаёт на «Стоит у …», гараж начинает подготовку
     * (`StartPrepOnArrival`, 06.10.2026: шага «Выдача — в гараж» у продажи больше нет).
     */
    public function released(VehicleReleased $e): void
    {
        if (! ($offer = $this->offer($e->vehicle))) {
            return;
        }
        $by = $e->by ?? $this->system();
        $offer->log(OfferEventType::Note, $e->by, ['text' => 'Выдана с парковки'.($e->vehicle->yard ? ' «'.$e->vehicle->yard->name.'»' : '')]);
        if ($car = $offer->garageCar()->first()) {
            // Вывоза могло и не быть (машина пришла на парковку раньше гаража) — встаёт сразу на «Стоит у …»; нет и
            // маршрута — подготовка начинается без него.
            $to = $car->manager_id ? Destination::Keeper : Destination::Ours;
            $workflow = $offer->position(Track::Service)?->stage->workflow ?? ($offer->vendor ? app(EnsureServiceWorkflow::class)($offer->vendor) : null);
            $stage = $workflow?->stageForPlace($to === Destination::Keeper ? CarPlace::Keeper : CarPlace::WithUs);
            $offer->update(['evacuation_to' => $to->value]);
            if ($stage) {
                ($this->place)($offer, $stage, $by);
            } elseif ($car->state === CarState::Waiting) {
                $car->moveTo(CarState::Repair, by: $by);
                GarageChanged::dispatch($car);
            }

            return;
        }
        $exit = $offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $offer->deal)->first(fn ($x) => mb_strtolower($x->label) === 'автомобиль передан');
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $by);
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
        // ТС вывозят мимо парковки (к менеджеру, к нам) — правда о её месте у ответственного, а не у стоянки.
        if (! ($offer = $this->offer($vehicle)) || ! $this->toYard($offer)) {
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

    private function toYard(Offer $offer): bool
    {
        return $offer->pickupDestination() === Destination::Yard;
    }

    private function offer(Vehicle $vehicle): ?Offer
    {
        return $vehicle->offer_id ? Offer::with('positions.stage.workflow')->find($vehicle->offer_id) : null;
    }

    private function system(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
