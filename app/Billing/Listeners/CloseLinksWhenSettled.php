<?php

namespace App\Billing\Listeners;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;

/** Счёт оплачен иначе или аннулирован — открытая ссылка по нему гаснет: платить по ней больше нечего. */
final class CloseLinksWhenSettled
{
    public function handle(PaymentRecorded|InvoiceVoided $e): void
    {
        $invoice = $e->invoice;
        if ($invoice->state === InvoiceState::Issued && $invoice->remaining() > 0) {
            return;
        }
        PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->update(['state' => PayLinkState::Canceled, 'canceled_at' => now()]);
    }
}
