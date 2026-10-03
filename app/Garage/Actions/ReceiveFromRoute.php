<?php

namespace App\Garage\Actions;

use App\Garage\CarState;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\GaragePayer;
use App\Garage\Payer;
use App\Offers\Deal;
use App\Users\User;

/**
 * Маршрут гаражной сделки дошёл до конца: со страховой всё, машину можно везти — этап «Доставка». Поставщику
 * платил менеджер — его оплата встаёт расходом сама, на закупочную (решение владельца 03.10.2026).
 */
final class ReceiveFromRoute
{
    public function __invoke(Deal $deal, ?User $by = null): void
    {
        $car = $deal->garageCar()->first();
        if (! $car || $car->state !== CarState::Waiting) {
            return;
        }
        $car->moveTo(CarState::Delivery);
        if ($deal->garage_payer === GaragePayer::Manager && $deal->cost && ! $car->costs()->where('kind', Cost::SUPPLIER)->exists()) {
            $car->costs()->create([
                'title' => 'Оплата поставщику',
                'amount' => $deal->cost,
                'spent_at' => now()->toDateString(),
                'payer' => Payer::Manager,
                'kind' => Cost::SUPPLIER,
                'created_by' => $by?->id,
            ]);
        }
        GarageChanged::dispatch($car);
    }
}
