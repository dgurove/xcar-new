<?php

namespace App\Billing\Listeners;

use App\Billing\Actions\IssueAgentFee;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;

/** Наши счета по сделке оплачены целиком — вознаграждение менеджера становится обязательством к выплате. */
final class PayoutWhenPaid
{
    public function __construct(private IssueAgentFee $issue) {}

    public function handle(PaymentRecorded $e): void
    {
        $invoice = $e->invoice;
        if ($invoice->state !== InvoiceState::Paid || $invoice->isOwed() || ! $invoice->deal_id || ! $e->by) {
            return;
        }
        $deal = $invoice->deal;
        if ($deal && $deal->isActive() && $deal->fullyPaid()) {
            ($this->issue)($deal, $e->by);
        }
    }
}
