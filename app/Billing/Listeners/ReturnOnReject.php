<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentRejected;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

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
        $exit = $offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $deal)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата не поступила'));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $e->by);
        }
    }
}
