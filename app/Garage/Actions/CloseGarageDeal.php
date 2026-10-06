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
 * Маршрут гаражной сделки дошёл до конца — бумаги со страховой закрыты (06.10.2026: где машина, решает вывоз, а не
 * сделка). Подготовленная машина («Готова») сама встаёт в продажу. Поставщику платил менеджер — его оплата встаёт
 * расходом сама, на закупочную (решение владельца 03.10.2026), на каком бы этапе машина ни была.
 */
final class CloseGarageDeal
{
    public function __invoke(Deal $deal, ?User $by = null): void
    {
        $car = $deal->garageCar()->first();
        if (! $car) {
            return;
        }
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
        if ($car->state === CarState::Ready) {
            $car->moveTo(CarState::Selling, by: $by);
        }
        GarageChanged::dispatch($car);
    }
}
