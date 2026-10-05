<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Events\PaymentConfirmed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Events\StageEntered;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Счёт по сделке оплачен целиком — маршрут сам делает шаг «Оплата получена». Если сделка ещё ждёт
 * платёжку менеджера (оплатили по ссылке, пришло по выписке, сотрудник отметил сам), сначала за менеджера
 * проходится «Платёжное поручение приложено»: деньги уже у нас, ждать его бумагу незачем. Подтверждение заявки
 * менеджера об оплате («Поступило») — тоже оплата: без этого сотрудник жал «Поступило» в деньгах и ещё раз
 * «Оплата получена» в пути, а одно не знало о другом.
 */
final class AdvanceOnPayment
{
    public function __construct(private TakeExit $take) {}

    public function handle(PaymentRecorded|PaymentConfirmed $e): void
    {
        $invoice = $e instanceof PaymentConfirmed ? $e->payment->invoice->refresh() : $e->invoice;
        // Вознаграждение от поставщика и наше обязательство менеджеру — не оплата покупателя, маршрут не двигают.
        if ($invoice->state !== InvoiceState::Paid || ! $invoice->deal_id || $invoice->isOwed() || $invoice->kind === ChargeKind::Reward) {
            return;
        }
        // Счетов у сделки бывает два (гаражная «платит менеджер»: машина и наша доля) — шаг оплаты проходит, только когда
        // оплачены все, а не тот, что заплатили первым.
        if ($invoice->deal?->fullyPaid()) {
            $this->advance($invoice->deal, $e->by);
        }
    }

    /**
     * Пришли на шаг оплаты, а счета сделки уже оплачены (подбор по ДКП выставлен при принятии, менеджер заплатил сразу) —
     * шаг проходит тут же: ждать нечего.
     */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! $e->exit || ! $e->to->isPayStep()) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if ($deal?->isActive() && $deal->fullyPaid()) {
            $this->advance($deal->setRelation('offer', $e->offer), $e->by);
        }
    }

    private function advance(?Deal $deal, ?User $by): void
    {
        $offer = $deal?->offer;
        if (! $offer || ! $deal->isActive()) {
            return;
        }
        $offer->unsetRelation('positions');
        $position = $offer->position(Track::Sale);
        $slip = $position?->stage->payExit($deal);
        if ($slip) {
            $deal->openRequirement()->where('stage_id', $position->stage_id)->first()?->update(['done_at' => now(), 'answer' => ['exit' => $slip->label, 'fields' => []]]);
            ($this->take)($offer, $slip, Actor::Manager, $by);
            $offer->log(OfferEventType::RequirementAnswered, $by, ['exit' => $slip->label, 'fields' => []]);
            $position = $offer->fresh()->position(Track::Sale);
        }
        $exit = $position?->stage->exitsFor(Actor::Staff, $deal)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $by);
        }
    }
}
