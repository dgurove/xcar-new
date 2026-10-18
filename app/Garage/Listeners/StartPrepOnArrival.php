<?php

namespace App\Garage\Listeners;

use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Logistics\Events\TransportMoved;

/**
 * Вывоз довёз гаражную машину («Привёз» в логистике) — к её менеджеру или, взятую под себя, к нам: начинается подготовка
 * (06.10.2026). Бумаги со страховой могут ещё идти — готовить можно, продавать нет (`MoveCar`). Выдачу гаражной с нашей
 * парковки ведёт `Park\Listeners\SyncOffer`.
 */
final class StartPrepOnArrival
{
    public function handle(TransportMoved $e): void
    {
        // На стоянку логиста — ещё не довезли (18.10.2026): подготовка — когда последний отрезок привезёт к менеджеру.
        if ($e->step !== 'delivered' || $e->transport->destination === \App\Logistics\Point::Depot || ! ($offer = $e->transport->saleOffer())) {
            return;
        }
        $car = $offer->garageCar()->first();
        if ($car?->state === CarState::Waiting) {
            $car->moveTo(CarState::Repair, by: $e->by);
            GarageChanged::dispatch($car);
        }
    }
}
