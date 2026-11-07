<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Events\StageEntered;
use App\Workflow\Stage;
use App\Workflow\Track;

/**
 * Данные покупателя для счёта поставщика собраны — шаг «Нет реквизитов» (`Asks::Buyer`) проходит сам своим
 * исходом менеджера (03.11.2026, Альфа: «пришлите реквизиты»): кто бы ни внёс — менеджер, его покупатель по ссылке или
 * админ со сканом из другой переписки. Готово — как для договора: у человека паспорт и обе страницы скана
 * (`Party::readyAsBuyer`), у организации — реквизиты. Зовёт `SettleContract` — одна дверь всех правок покупателя.
 */
final class AdvanceOnBuyerData
{
    public function __construct(private TakeExit $take) {}

    public function __invoke(Deal $deal): bool
    {
        $deal->loadMissing(['offer', 'contract.buyer.party']);
        // Параллельная (07.11.2026): данные готовы — её теневой шаг дальше («Письмо с реквизитами»), машина стоит; нам —
        // «прислал реквизиты» (`ParallelBuyerNotice`).
        if ($deal->isParallel()) {
            return app(ParallelBuyerReady::class)($deal);
        }
        $stage = $deal->offer?->unsetRelation('positions')->stage(Track::Sale);
        if (! $deal->isActive() || $stage?->asks !== Asks::Buyer || ! $deal->contract?->buyer?->party?->readyAsBuyer()) {
            return false;
        }
        $exit = $stage->exitsFor(Actor::Manager, $deal)->first();
        if (! $exit) {
            return false;
        }
        ($this->take)($deal->offer, $exit, Actor::Manager, $deal->buyer, ['label' => 'Данные покупателя заполнены']);

        return true;
    }

    /**
     * Сменили схему на шаге реквизитов (`ChangeDealScheme`): «страховой напрямую» без готового покупателя, а письмо с
     * реквизитами ещё не ушло — назад на «Нет реквизитов» (менеджеру задача); стало «платим мы» (ПРАЙМ, Р.О.С.)
     * на шаге данных — сразу письмо с нашими реквизитами.
     */
    public function rehome(Deal $deal, User $by): void
    {
        $offer = $deal->offer->unsetRelation('positions');
        $position = $offer->position(Track::Sale);
        $stage = $position?->stage;
        if (! $deal->isActive() || ! $stage) {
            return;
        }
        $ours = $deal->schemeOf()->paysCar() || $deal->isGarageUs();
        if ($stage->asks === Asks::Buyer && $ours) {
            if ($exit = $stage->exitsFor(Actor::Manager, $deal)->first()) {
                ($this->take)($offer, $exit, Actor::Manager, $by, ['label' => 'Схема сменилась: наши реквизиты']);
            }

            return;
        }
        $data = $stage->isRequisitesStep() && ! $ours && in_array($position->letterStatus(), [null, 'unsent', 'failed'], true)
            ? Stage::where('workflow_id', $stage->workflow_id)->where('asks', Asks::Buyer->value)->first() : null;
        if ($data && ! $deal->contract?->buyer?->party?->readyAsBuyer()) {
            app(EnterStage::class)($offer, $data, $by, ['label' => 'Схема сменилась: нужны данные покупателя'], back: true);
        }
    }

    /** Вошли на шаг, а данные уже есть (вернули шаг назад, покупатель тот же) — шаг проходит сразу. */
    public function entered(StageEntered $e): void
    {
        if ($e->track === Track::Sale && $e->to->asks === Asks::Buyer && ($deal = $e->deal ?? $e->offer->deal()->first())) {
            $this($deal->setRelation('offer', $e->offer));
        }
    }
}
