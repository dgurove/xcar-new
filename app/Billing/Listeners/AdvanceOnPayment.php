<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentConfirmed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Offers\Actions\MoneyIn;
use App\Offers\Actions\SetDealDate;
use App\Offers\Actions\StampDealMoney;
use App\Offers\DealDates;
use App\Offers\Deal;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;

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
        if ($invoice->state !== InvoiceState::Paid || ! $invoice->deal_id || $invoice->isOwed()) {
            return;
        }
        // Счетов у сделки бывает два (гаражная «платит менеджер»: машина и наша доля) — шаг оплаты проходит, только когда
        // оплачены все, а не тот, что заплатили первым.
        // С 26.11.2026 — и когда оплачен счёт за ТС, а ссылка за подбор ещё нет (`Deal::payStepPaid`).
        if ($invoice->deal?->payStepPaid()) {
            $this->pass($invoice->deal, $e->by);
        }
    }

    /**
     * Пришли на шаг оплаты, а счета сделки уже оплачены (подбор по ДКП выставлен при принятии, менеджер заплатил сразу) или
     * деньги без счёта уже пришли (`MoneyIn::closes`, 17.11.2026) — шаг проходит тут же: ждать нечего.
     */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! $e->exit) {
            return;
        }
        // Шаг нашей оплаты поставщику, а заплатили раньше (выписка поставила «Оплачено», пока сделка была на «Договор получен»)
        // — проходит сразу, с той датой (17.11.2026).
        if ($e->to->paysOut()) {
            $deal = $e->deal ?? $e->offer->deal()->first();
            if ($deal?->isActive() && $deal->supplier_paid_at && DealDates::paidColumn($deal) === 'supplier_paid_at') {
                $e->offer->unsetRelation('positions');
                $tx = StampDealMoney::firstOut($deal);
                app(SetDealDate::class)->passPaid($deal, $e->offer, $deal->supplier_paid_at, $e->by, $tx?->doc_number);
            }

            return;
        }
        if (! $e->to->isPayStep()) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if ($deal?->isActive() && ($deal->payStepPaid() || MoneyIn::closes($deal->setRelation('offer', $e->offer)))) {
            $this->pass($deal->setRelation('offer', $e->offer), $e->by);
        }
    }

    /**
     * Пройти шаг оплаты: за менеджера «Платёжное поручение приложено», за нас «Оплата получена». Зовут оплата счёта и
     * `MoneyIn` (деньги без счёта). @return bool сдвинулся ли шаг
     */
    public function pass(?Deal $deal, ?User $by): bool
    {
        $moved = false;
        $offer = $deal?->offer;
        // Покупатель с гаражом платит свою цену долгом (20.10.2026): его счёт с нашим маршрутом со страховой не связан.
        if (! $offer || ! $deal->isActive() || $deal->forGarageBuyer()) {
            return false;
        }
        $offer->unsetRelation('positions');
        $position = $offer->position(Track::Sale);
        // За ТС платят поставщику сами (Альфа, «Страховой напрямую»): оплата нашего подбора — не оплата поставщику, шаг
        // ждёт платёжку менеджера (18.10.2026: иначе Альфе уходило «счёт оплачен», хотя ей не платили).
        if ($deal->paysSupplierOn($position?->stage)) {
            return false;
        }
        $slip = $position?->stage->payExit($deal);
        if ($slip) {
            Requirement::closeOpen($deal, $position->stage_id, ['closed_by' => 'payment']);
            // Строки «Менеджер: «Платёжное поручение приложено»» нет: деньги отметил сотрудник, пришли по ссылке или выписке —
            // менеджер ничего не отвечал (17.10.2026, прогон); его заявку об оплате ведёт `AdvanceOnClaim`. В истории — этап.
            ($this->take)($offer, $slip, Actor::Manager, $by);
            $moved = true;
            $position = $offer->fresh()->position(Track::Sale);
        }
        // Оплата до вывоза (Альфа, `pay_before_pickup`): «Оплата получена» на этапе, что ждёт поставщика, — это его
        // подтверждение. Наш счёт (подбор, машина по ПРАЙМ) его не заменяет: иначе оплата подбора по ссылке ставила
        // `prepaid_at`, и менеджеру открывался вывоз, хотя Альфе ещё не заплатили. Отмечает сотрудник по ответу поставщика.
        if ($offer->vendor?->pay_before_pickup && $position?->stage->waits_for === WaitsFor::Supplier) {
            return $moved;
        }
        $exit = $position?->stage->exitsFor(Actor::Staff, $deal)->first(fn ($x) => str_starts_with(mb_strtolower($x->label), 'оплата получена'));
        if ($exit) {
            ($this->take)($offer, $exit, Actor::Staff, $by);
            $moved = true;
        }

        return $moved;
    }
}
