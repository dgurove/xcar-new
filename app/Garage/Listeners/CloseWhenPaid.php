<?php

namespace App\Garage\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Garage\Car;
use App\Garage\CarState;

/** Счёт по машине оплачен — расчёт закрыт: состояние ставится само, руками отмечать нечего. */
final class CloseWhenPaid
{
    public function handle(PaymentRecorded $e): void
    {
        if ($e->invoice->state !== InvoiceState::Paid) {
            return;
        }

        Car::where('invoice_id', $e->invoice->id)->where('state', CarState::Sold)
            ->update(['state' => CarState::Settled, 'settled_at' => now()]);
    }
}
