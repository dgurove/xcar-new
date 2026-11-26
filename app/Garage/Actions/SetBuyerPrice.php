<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\SyncDealInvoices;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Цена машины покупателю с гаражом правится (20.10.2026, владелец: «установили цену для покупателя — должна быть
 * возможность её редактировать»): пока за его счёт не платили и он не сообщал об оплате. Счёт на прежнюю цену
 * аннулируется и встаёт на новую сам (`SyncDealInvoices`); до согласия поставщика счёта ещё нет — просто новая цена.
 */
final class SetBuyerPrice
{
    public function __construct(private SyncDealInvoices $sync) {}

    public function __invoke(Car $car, int $price, User $by): Car
    {
        if (! $car->forBuyer()) {
            throw ValidationException::withMessages(['buyer_price' => 'ТС не у покупателя']);
        }
        if ($car->state === CarState::Settled) {
            throw ValidationException::withMessages(['buyer_price' => 'Расчёт закрыт']);
        }
        if ($car->buyerHasPaid()) {
            throw ValidationException::withMessages(['buyer_price' => 'По счёту уже есть оплата: сначала верните её']);
        }
        $was = $car->buyer_price;
        if ($was === $price) {
            return $car;
        }
        $car->update(['buyer_price' => $price]);
        $car->log($by, ['do' => 'buyer_price', 'amount' => $price, 'was' => $was]);
        if ($car->deal) {
            ($this->sync)($car->deal->fresh(), $by);
        }
        GarageChanged::dispatch($car);

        return $car;
    }
}
