<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Deal;
use App\Users\User;

/**
 * Гаражная сделка заведена — машина встаёт менеджеру в гараж «Ждёт машину» (её привезёт вывоз). Платим поставщику
 * мы — закупочная сразу «отдали за»; платит менеджер — его оплата встанет расходом, когда сделка закроется.
 * Строка гаража одна на предложение; первый шаг пути — строкой «Истории» машины (`Car::stages`).
 */
final class ReserveCar
{
    /** `$buyerPrice` — машина у покупателя с гаражом: его цена (`Car::forBuyer`), закупочной он не видит. */
    public function __invoke(Deal $deal, ?User $by = null, ?int $buyerPrice = null): Car
    {
        // Прежнюю ждущую (сделку отдали другому) снимает CancelDeal, здесь — на всякий случай.
        Car::where('offer_id', $deal->offer_id)->where('state', CarState::Waiting)->delete();
        $car = Car::create([
            'offer_id' => $deal->offer_id,
            'deal_id' => $deal->id,
            'manager_id' => $deal->buyer_id,
            'state' => CarState::Waiting,
            'taken_at' => now(),
            'stage_at' => now(),
            'created_by' => $by?->id,
            'buyer_price' => $buyerPrice,
        ]);
        // Первый шаг пути машины — строкой «Истории» (`Car::stages`).
        $car->log($by, ['do' => 'stage', 'state' => CarState::Waiting->value]);
        GarageChanged::dispatch($car);

        return $car;
    }
}
