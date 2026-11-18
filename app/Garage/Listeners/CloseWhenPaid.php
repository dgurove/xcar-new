<?php

namespace App\Garage\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;

/**
 * Счёт по машине оплачен — менеджером или его покупателем — расчёт закрыт: состояние ставится само, руками отмечать нечего
 * (выплат менеджеру после оплаты покупателя нет с 18.11.2026).
 */
final class CloseWhenPaid
{
    public function handle(PaymentRecorded $e): void
    {
        if ($e->invoice->state !== InvoiceState::Paid) {
            return;
        }

        $car = Car::where('state', CarState::Sold)->where('invoice_id', $e->invoice->id)->first();
        // Покупатель с гаражом оплатил свой счёт (счёт сделки), а ТС уже продана — расчёт закрыт (20.10.2026).
        $car ??= $e->invoice->deal_id ? Car::where('state', CarState::Sold)->where('deal_id', $e->invoice->deal_id)->first() : null;
        if ($car && ($car->invoice_id === $e->invoice->id || $car->buyerPaid())) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $e->by);
            GarageChanged::dispatch($car);
        }
    }
}
