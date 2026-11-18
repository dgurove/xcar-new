<?php

namespace App\Garage\Listeners;

use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentVoided;
use App\Billing\InvoiceState;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;

/**
 * Счёт аннулировали — машина снова ждёт расчёта и счёт можно выставить заново;
 * отменили оплату — «расчёт закрыт» снимается. Обратимость как на парковке.
 */
final class ReopenWhenVoided
{
    public function handle(InvoiceVoided|PaymentVoided $e): void
    {
        $invoice = $e instanceof PaymentVoided ? $e->payment->invoice : $e->invoice;
        $car = Car::where('invoice_id', $invoice->id)->first();
        if (! $car) {
            return;
        }

        if ($invoice->state === InvoiceState::Void) {
            $car->update(['invoice_id' => null, 'invoice_to' => null, 'state' => CarState::Sold, 'settled_at' => null]);
        } elseif ($car->state === CarState::Settled && $invoice->fresh()->state !== InvoiceState::Paid) {
            $car->update(['state' => CarState::Sold, 'settled_at' => null]);
        }
        if ($invoice->state === InvoiceState::Void) {
            $car->log($e->by, ['do' => 'void', 'amount' => (float) $invoice->total]);
        }
        GarageChanged::dispatch($car);
    }
}
