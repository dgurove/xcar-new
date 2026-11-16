<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Events\PaymentRejected;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;
use App\Workflow\WaitsFor;

/**
 * «Не поступила» на заявке менеджера об оплате — маршрут сам делает шаг «Оплата не поступила»: сделка снова
 * ждёт платёжку, и сотруднику не нужно отказывать дважды — в деньгах и в пути.
 */
final class ReturnOnReject
{
    public function __construct(private TakeExit $take) {}

    public function handle(PaymentRejected $e): void
    {
        $deal = $e->payment->invoice?->deal;
        $offer = $deal?->offer;
        if (! $offer || ! $deal->isActive()) {
            return;
        }
        // Не поступила оплата нашего подбора, а шаг ждёт подтверждения страховой о платеже поставщику (16.11.2026): подбор
        // этот шаг не держит — возвращать сделку на «Оплату» нельзя, чек поставщику уже у страховой.
        $stage = $offer->position(Track::Sale)?->stage;
        if ($stage?->waits_for === WaitsFor::Supplier && $e->payment->invoice->kind === ChargeKind::Selection && $deal->paysSelection()) {
            return;
        }
        $exit = $stage?->exitsFor(Actor::Staff, $deal)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата не поступила'));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $e->by);
        }
    }
}
