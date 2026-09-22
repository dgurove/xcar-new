<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentClaimed;
use App\Offers\OfferEventType;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Менеджер приложил платёжку к счёту — маршрут сам делает его шаг «Платёжное
 * поручение приложено», а просьба этапа закрывается: документ живёт у счёта, не у просьбы.
 */
final class AdvanceOnClaim
{
    public function __construct(private TakeExit $take) {}

    public function handle(PaymentClaimed $e): void
    {
        $invoice = $e->payment->invoice;
        $deal = $invoice->deal;
        if (! $deal || ! $deal->isActive()) {
            return;
        }
        $offer = $deal->offer;
        $position = $offer->position(Track::Sale);
        $exit = $position?->stage->exitsFor(Actor::Manager)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'платёжное поручение'));
        if (! $exit) {
            return;
        }
        $requirement = $deal->openRequirement()->where('stage_id', $position->stage_id)->first();
        $requirement?->update(['done_at' => now(), 'answer' => ['exit' => $exit->label, 'fields' => []]]);
        ($this->take)($offer, $exit, Actor::Manager, $e->by);
        $offer->log(OfferEventType::RequirementAnswered, $e->by, ['exit' => $exit->label, 'fields' => []]);
    }
}
