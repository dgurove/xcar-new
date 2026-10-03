<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Garage\GaragePayer;
use App\Offers\Deal;
use App\Users\User;

/**
 * Гаражная сделка заведена — машина встаёт менеджеру в гараж «Ждёт страховую». Платим поставщику мы — закупочная
 * сразу «отдали за»; платит менеджер — его оплата встанет расходом, когда маршрут дойдёт до конца.
 */
final class ReserveCar
{
    public function __invoke(Deal $deal, ?User $by = null): Car
    {
        // Строка гаража одна на предложение: прежнюю ждущую (сделку отдали другому) снимает CancelDeal, здесь — на всякий случай.
        Car::where('offer_id', $deal->offer_id)->where('state', CarState::Waiting)->delete();
        $car = Car::create([
            'offer_id' => $deal->offer_id,
            'deal_id' => $deal->id,
            'manager_id' => $deal->buyer_id,
            'state' => CarState::Waiting,
            'taken_at' => now(),
            'stage_at' => now(),
            'history' => [[CarState::Waiting->value, now()->toIso8601String()]],
            'cost' => $deal->garage_payer === GaragePayer::Us ? $deal->cost : null,
            'created_by' => $by?->id,
        ]);
        GarageChanged::dispatch($car);

        return $car;
    }
}
