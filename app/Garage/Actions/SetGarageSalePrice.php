<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Цена продажи у покупателя с гаражом (26.11.2026, владелец: «он не должен нигде видеть цену закупа; цену продажи для того
 * покупателя он назначает сам либо мы за него»; вознаграждения у него нет). Цена продажи (`deals.amount`) — сумма договора и счёта
 * его клиенту (`DealContract::priceFor`, `SyncDealInvoices::prime`); его цена гаража (`garage_cars.buyer_price`) — наш нижний предел,
 * ему не видна: ниже неё держателю — отказ без суммы, сотрудник ставит любую. До 26.11 была модель «его цена + вознаграждение».
 */
final class SetGarageSalePrice
{
    public function __construct(private SyncDealInvoices $sync) {}

    public function __invoke(Car $car, int $price, User $by): Car
    {
        $deal = $car->deal;
        if (! $car->forBuyer() || ! $deal) {
            throw ValidationException::withMessages(['sale_price' => 'ТС не у покупателя']);
        }
        if ($car->state === CarState::Settled) {
            throw ValidationException::withMessages(['sale_price' => 'Расчёт закрыт']);
        }
        if ($car->buyerHasPaid()) {
            throw ValidationException::withMessages(['sale_price' => 'По счёту уже есть оплата: цену не поменять']);
        }
        if (! $by->garageStaff() && $price < (int) $car->buyer_price) {
            throw ValidationException::withMessages(['sale_price' => 'Слишком низкая цена']);
        }
        $was = $deal->amount;
        if ($was !== null && (int) $was === $price) {
            return $car;
        }
        $deal->update(['amount' => $price, 'commission' => null]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Цена продажи '.Money::rub($price).($was !== null ? ' (была '.Money::rub($was).')' : '')]);
        // Договор и неоплаченный счёт — на новую цену.
        ($this->sync)($deal->fresh(), $by);
        GarageChanged::dispatch($car);

        return $car;
    }
}
