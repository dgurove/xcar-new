<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Offers\OfferEventType;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Счёт по сделке оплачен целиком — маршрут сам делает шаг «Оплата получена». Если сделка ещё ждёт
 * платёжку менеджера (оплатили по ссылке, пришло по выписке, сотрудник отметил сам), сначала за менеджера
 * проходится «Платёжное поручение приложено»: деньги уже у нас, ждать его бумагу незачем.
 */
final class AdvanceOnPayment
{
    public function __construct(private TakeExit $take) {}

    public function handle(PaymentRecorded $e): void
    {
        $invoice = $e->invoice;
        // Вознаграждение от поставщика и наше обязательство менеджеру — не оплата покупателя, маршрут не двигают.
        if ($invoice->state !== InvoiceState::Paid || ! $invoice->deal_id || $invoice->isOwed() || $invoice->kind === ChargeKind::Reward) {
            return;
        }
        $deal = $invoice->deal;
        $offer = $deal?->offer;
        if (! $offer || ! $deal->isActive()) {
            return;
        }
        $position = $offer->position(Track::Sale);
        $slip = $position?->stage->exitsFor(Actor::Manager)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'платёжное поручение'));
        if ($slip) {
            $deal->openRequirement()->where('stage_id', $position->stage_id)->first()?->update(['done_at' => now(), 'answer' => ['exit' => $slip->label, 'fields' => []]]);
            ($this->take)($offer, $slip, Actor::Manager, $e->by);
            $offer->log(OfferEventType::RequirementAnswered, $e->by, ['exit' => $slip->label, 'fields' => []]);
            $position = $offer->fresh()->position(Track::Sale);
        }
        $exit = $position?->stage->exitsFor(Actor::Staff)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $e->by);
        }
    }
}
