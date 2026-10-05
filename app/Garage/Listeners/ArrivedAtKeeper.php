<?php

namespace App\Garage\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\CarPlace;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;

/**
 * Вывоз довёз машину к менеджеру в гараж, а она ещё на «Доставке» — «Привёз» уже случился: сразу «Подготовка».
 * Машину на «Ждёт страховую» ставит туда конец маршрута сделки (`ReceiveFromRoute`).
 */
final class ArrivedAtKeeper
{
    public function handle(StageEntered $e): void
    {
        if ($e->track !== Track::Service || $e->to->car_place !== CarPlace::Keeper) {
            return;
        }
        $car = $e->offer->garageCar()->first();
        if ($car?->state === CarState::Delivery) {
            $car->moveTo(CarState::Repair);
            GarageChanged::dispatch($car);
        }
    }
}
