<?php

namespace App\Garage\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\CarPlace;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;

/**
 * Вывоз довёз гаражную машину — к её менеджеру («Стоит у менеджера») или, взятую под себя, к нам: начинается
 * подготовка (06.10.2026). Бумаги со страховой могут ещё идти — готовить можно, продавать нет (`MoveCar`).
 */
final class StartPrepOnArrival
{
    public function handle(StageEntered $e): void
    {
        if ($e->track !== Track::Service || ! in_array($e->to->car_place, [CarPlace::Keeper, CarPlace::WithUs], true)) {
            return;
        }
        $car = $e->offer->garageCar()->first();
        if ($car?->state === CarState::Waiting) {
            $car->moveTo(CarState::Repair, by: $e->by);
            GarageChanged::dispatch($car);
        }
    }
}
