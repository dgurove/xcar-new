<?php

namespace App\Garage\Actions;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Channel;
use App\Logistics\Point;
use App\Offers\Actions\AssignDealPickup;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;

/**
 * У гаражной машины вывоз есть всегда (06.10.2026): где она физически — это он, а не этап гаража. С 17.10.2026 это задача
 * логистики к её менеджеру (`Point::Keeper`), без менеджера — к нам. Кто везёт: сам менеджер (`$evacuator` — он) или
 * «Логистика» (null — исполнителя выберет админ в окне «Кто вывозит»). `arrived` — машина уже у менеджера: задачи нет,
 * место «у менеджера», подготовка сразу (и когда ТС уже забрана — вывоз её привёз туда). Забрали уже — вывоз не
 * заводим; машина на нашей парковке (её двигает выдача, `SyncOffer`) — не трогаем.
 */
final class EnsurePickup
{
    public function __construct(private OpenPickup $open, private AssignExecutor $assign, private SetCarPlace $place) {}

    /** `$later` — «Не определено» (21.10.2026): кто повезёт, решат потом; задача тихо и с меткой. */
    public function __invoke(Offer $offer, User $by, ?User $evacuator = null, string $where = 'owner', bool $later = false): Offer
    {
        $offer = $offer->fresh(['parkVehicle', 'garageCar.manager']);
        $car = $offer->garageCar;
        if (! $car || $offer->parkVehicle?->state === VehicleState::Stored) {
            return $offer;
        }
        // Без менеджера («взяли под себя») — куда везти, решают при назначении: «к нам» без адреса — не место (18.10.2026).
        $to = $car->manager_id ? Point::Keeper : null;
        if ($where === 'arrived') {
            ($this->place)($offer, $to?->place() ?? CarPlace::WithUs, $by);
            if ($car->state === CarState::Waiting) {
                $car->moveTo(CarState::Repair, by: $by);
                GarageChanged::dispatch($car);
            }

            return $offer;
        }
        // Уже забрали — вывоз не заводим. «Уже у …» выше — и при забранной (21.10.2026, 617472: вывезли «Уже вывезли» к
        // Ананьеву, а гараж застревал на «Ждёт ТС»).
        if ($offer->pickedUp()) {
            return $offer;
        }
        // Везёт сам менеджер — только к себе, и это не логистика: тихо (`OpenPickup` quiet).
        $self = $evacuator && $car->manager_id === $evacuator->id;
        $t = ($this->open)($offer, $by, $to, quiet: $self || $later);
        if ($to && $t->destination !== $to) {
            $t->update(['destination' => $to]);
        }
        if ($self && ! $t->isExecutedBy($evacuator)) {
            ($this->assign)($t, Channel::Manager, $evacuator, $by);
        }
        AssignDealPickup::undecided($t, $later && ! $self);

        return $offer;
    }
}
