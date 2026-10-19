<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentClaimed;
use App\Billing\PaymentSource;
use App\Offers\OfferEventType;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Менеджер приложил платёжку к счёту — маршрут сам делает его шаг «Платёжное
 * поручение приложено», а просьба этапа закрывается: документ живёт у счёта, не у просьбы.
 * Отдал наличными (19.10.2026, владелец) — тот же выход, но в ответе и «Истории» так и написано: `label` рядом с `exit`
 * (по `exit` ответ ищут — письмо поставщику с платёжкой, «Расчёт» менеджера).
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
        // Р.О.С. (Альфа с НДС): дальше — наш перевод этих денег Альфе. По одной заявке «оплатил» шаг не идёт — иначе
        // «Наш ход: переведите» вставал раньше, чем деньги пришли на Р.О.С.; его откроет «Поступило» (`AdvanceOnPayment`).
        if ($deal->schemeOf()->paysCar() && $deal->offer?->vendor?->pay_before_pickup) {
            return;
        }
        // Сообщил об оплате одного счёта, а другой (машина или доля) ещё не оплачен и не заявлен — шаг ждёт.
        $open = $deal->issuedInvoices()->where('kind', '!=', \App\Billing\ChargeKind::Reward)->where('state', \App\Billing\InvoiceState::Issued)->with('claims')->get();
        if ($open->contains(fn ($i) => $i->claims->isEmpty() && $i->remaining() > 0)) {
            return;
        }
        $offer = $deal->offer;
        $position = $offer->position(Track::Sale);
        // Сообщил об оплате нашего подбора, а за ТС платит поставщику сам — шаг ждёт его платёжку поставщику.
        if ($deal->paysSupplierOn($position?->stage)) {
            return;
        }
        $exit = $position?->stage->payExit($deal);
        if (! $exit) {
            return;
        }
        $said = $e->payment->source === PaymentSource::Cash ? ['label' => 'Отдал наличными'] : [];
        $requirement = $deal->openRequirement()->where('stage_id', $position->stage_id)->first();
        $requirement?->update(['done_at' => now(), 'answer' => ['exit' => $exit->label, ...$said, 'fields' => []]]);
        ($this->take)($offer, $exit, Actor::Manager, $e->by, $said);
        $offer->log(OfferEventType::RequirementAnswered, $e->by, ['exit' => $exit->label, ...$said, 'fields' => []]);
    }
}
