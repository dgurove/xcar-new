<?php

namespace App\Garage\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;

/**
 * Счёт менеджеру оплачен или выплата ему сделана — расчёт закрыт: состояние ставится само, руками отмечать нечего.
 * Оплата покупателя расчёт не закрывает — за ней выплата менеджеру (`PayoutWhenBuyerPaid`).
 */
final class CloseWhenPaid
{
    public function handle(PaymentRecorded $e): void
    {
        if ($e->invoice->state !== InvoiceState::Paid) {
            return;
        }

        $car = Car::where('state', CarState::Sold)->where(fn ($q) => $q
            ->where(fn ($m) => $m->where('invoice_id', $e->invoice->id)->where(fn ($t) => $t->whereNull('invoice_to')->orWhere('invoice_to', 'manager')))
            ->orWhere('payout_invoice_id', $e->invoice->id))->first();
        // Покупатель с гаражом оплатил свой счёт (счёт сделки), а ТС уже продана — расчёт закрыт (20.10.2026).
        $car ??= $e->invoice->deal_id ? Car::where('state', CarState::Sold)->where('deal_id', $e->invoice->deal_id)->first() : null;
        if ($car && ($car->invoice_id === $e->invoice->id || $car->payout_invoice_id === $e->invoice->id || $car->buyerPaid())) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $e->by);
            GarageChanged::dispatch($car);
        }
    }
}
