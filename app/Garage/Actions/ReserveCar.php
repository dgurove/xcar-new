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
 * Строка гаража одна на предложение: отданная руками до 06.10.2026 без сделки получает её, этап не меняется.
 */
final class ReserveCar
{
    public function __invoke(Deal $deal, ?User $by = null): Car
    {
        $legacy = Car::where('offer_id', $deal->offer_id)->whereNull('deal_id')->first();
        if ($legacy && ! $legacy->isSold()) {
            // Закупочная — у сделки (`Car::base`), своя колонка у строки со сделкой пуста.
            $legacy->update(['deal_id' => $deal->id, 'manager_id' => $deal->buyer_id, 'cost' => null]);
            GarageChanged::dispatch($legacy);

            return $legacy;
        }
        // Прежнюю ждущую (сделку отдали другому) снимает CancelDeal, здесь — на всякий случай.
        Car::where('offer_id', $deal->offer_id)->where('state', CarState::Waiting)->delete();
        $car = Car::create([
            'offer_id' => $deal->offer_id,
            'deal_id' => $deal->id,
            'manager_id' => $deal->buyer_id,
            'state' => CarState::Waiting,
            'taken_at' => now(),
            'stage_at' => now(),
            'history' => [[CarState::Waiting->value, now()->toIso8601String()]],
            'created_by' => $by?->id,
        ]);
        GarageChanged::dispatch($car);

        return $car;
    }
}
