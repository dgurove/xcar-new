<?php

namespace App\Billing\Listeners;

use App\Billing\Actions\VoidInvoice;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentVoided;
use App\Billing\Invoice;
use App\Billing\InvoiceState;

/** Оплата покупателя отменена или счёт аннулирован — невыплаченное вознаграждение менеджера снова не к выплате. */
final class RevokeAgentFee
{
    public function __construct(private VoidInvoice $void) {}

    public function handle(InvoiceVoided|PaymentVoided $e): void
    {
        $invoice = $e instanceof PaymentVoided ? $e->payment->invoice : $e->invoice;
        if (! $invoice || ! $invoice->deal_id || $invoice->isOwed()) {
            return;
        }
        $deal = $invoice->deal;
        $fee = $deal?->agentFee()->first();
        if ($fee && $fee->state === InvoiceState::Issued && $fee->paid == 0 && ! $deal->fullyPaid()) {
            ($this->void)($fee, $e->by, 'Оплата по сделке отменена');
        }
    }
}
