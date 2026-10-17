<?php

namespace App\Garage\Actions;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Executor;
use App\Logistics\Point;
use App\Offers\Offer;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;

/**
 * У гаражной машины вывоз есть всегда (06.10.2026): где она физически — это он, а не этап гаража. С 17.10.2026 это задача
 * логистики к её менеджеру (`Point::Keeper`), без менеджера — к нам. Кто везёт: сам менеджер (`$evacuator` — он) или
 * «Логистика» (null — исполнителя выберет админ в окне «Кто вывозит»). `arrived` — машина уже у менеджера: задачи нет,
 * место «у менеджера», подготовка сразу. Забрали уже или машина на нашей парковке (её двигает выдача, `SyncOffer`) — не
 * трогаем.
 */
final class EnsurePickup
{
    public function __construct(private OpenPickup $open, private AssignExecutor $assign, private SetCarPlace $place) {}

    public function __invoke(Offer $offer, User $by, ?User $evacuator = null, string $where = 'owner'): Offer
    {
        $offer = $offer->fresh(['parkVehicle', 'garageCar.manager']);
        $car = $offer->garageCar;
        if (! $car || $offer->pickedUp() || $offer->parkVehicle?->state === VehicleState::Stored) {
            return $offer;
        }
        $to = $car->manager_id ? Point::Keeper : Point::Ours;
        if ($where === 'arrived') {
            ($this->place)($offer, $to->place(), $by);
            if ($car->state === CarState::Waiting) {
                $car->moveTo(CarState::Repair, by: $by);
                GarageChanged::dispatch($car);
            }

            return $offer;
        }
        // Везёт сам менеджер — только к себе, и это не логистика: тихо (`OpenPickup` quiet).
        $self = $evacuator && $car->manager_id === $evacuator->id;
        $t = ($this->open)($offer, $by, $to, quiet: $self);
        if ($t->destination !== $to) {
            $t->update(['destination' => $to]);
        }
        if ($self && ! $t->isExecutedBy($evacuator)) {
            ($this->assign)($t, Executor::Manager, $evacuator, $by);
        }

        return $offer;
    }
}
