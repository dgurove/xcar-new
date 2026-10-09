<?php

namespace App\Park\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\CarPlace;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\Events\VehicleAccepted;
use App\Park\Events\VehicleReleased;
use App\Park\Events\VehicleRestored;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\EnsureServiceWorkflow;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\SetCarPlace;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * ТС с парковки в продаже (`PutOnSale`, `Sale::adopt`) — одна машина: где она, знает парковка, «где машина» у
 * предложения — производное. Маршрут вывоза CRM парковка не двигает и не снимает (08.10.2026: вывоз CRM на парковку не
 * везёт). Без связанного предложения слушатель молчит.
 */
final class SyncOffer
{
    public function __construct(private PlaceOnStage $place, private SetCarPlace $setPlace, private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            VehicleAccepted::class => 'accepted',
            VehicleReleased::class => 'released',
            VehicleRestored::class => 'restored',
        ];
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

    /** У предложения свой вывоз в CRM (к менеджеру, к нам) — где машина, решает он, а не приём на парковке. */
    private function moveTo(Vehicle $vehicle, CarPlace $place, ?User $by): void
    {
        if (($offer = $this->offer($vehicle)) && ! $offer->position(Track::Service)) {
            ($this->setPlace)($offer, $place, $by);
        }
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
