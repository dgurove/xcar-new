<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;

/**
 * Данные покупателя для счёта поставщика собраны — шаг «Данные покупателя для счёта» (`Asks::Buyer`) проходит сам своим
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

    /** Вошли на шаг, а данные уже есть (вернули шаг назад, покупатель тот же) — шаг проходит сразу. */
    public function entered(StageEntered $e): void
    {
        if ($e->track === Track::Sale && $e->to->asks === Asks::Buyer && ($deal = $e->deal ?? $e->offer->deal()->first())) {
            $this($deal->setRelation('offer', $e->offer));
        }
    }
}
