<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/** Счёт по сделке оплачен целиком — маршрут сам делает шаг «Оплата получена», если такой исход есть у текущего этапа. */
final class AdvanceOnPayment
{
    public function __construct(private TakeExit $take) {}

    public function handle(PaymentRecorded $e): void
    {
        $invoice = $e->invoice;
        if ($invoice->state !== InvoiceState::Paid || ! $invoice->deal_id) {
            return;
        }
        $offer = $invoice->deal?->offer;
        $position = $offer?->position(Track::Sale);
        $exit = $position?->stage->exitsFor(Actor::Staff)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
        if ($offer && $exit) {
            ($this->take)($offer, $exit, Actor::Staff, $e->by);
        }
    }
}
