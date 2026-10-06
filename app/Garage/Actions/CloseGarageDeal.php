<?php

namespace App\Garage\Actions;

use App\Garage\CarState;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\GaragePayer;
use App\Garage\Payer;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Users\User;
use App\Workflow\Track;

/**
 * Маршрут гаражной сделки дошёл до конца: со страховой всё, машину можно везти — этап «Доставка» (довёз её к
 * менеджеру наш вывоз — сразу «Подготовка»). Поставщику
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
        // Вывоз уже довёз её к менеджеру — «Привёз» жать нечего, сразу подготовка.
        $car->moveTo($deal->offer?->position(Track::Service)?->stage->car_place === CarPlace::Keeper ? CarState::Repair : CarState::Delivery);
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
