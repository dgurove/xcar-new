<?php

namespace App\Offers\Actions;

use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Changes;
use App\Users\User;

/**
 * Новая закупочная у поставщика по идущей сделке (15.10.2026, владелец: «закупочная на момент подтверждения должна
 * закрепляться… новая не показывается никому — ни модераторам, ни менеджерам, ни покупателям»). Цена менеджера
 * `deals.cost` не меняется ничем — ни договор, ни счета, ни ссылки, ни уведомления; новая ложится в
 * `deals.supplier_cost` (только админу: маржа, оплата поставщику). Равна закреплённой — поле пустое. Зовёт `UpdateOffer`,
 * когда правят закупочную предложения с идущей сделкой.
 */
final class UpdateDealCost
{
    public function __invoke(Deal $deal, int $cost, ?User $by): void
    {
        $deal->supplier_cost = $cost === $deal->cost ? null : $cost;
        if (! $deal->isDirty('supplier_cost')) {
            return;
        }
        $diff = Changes::of($deal);
        $deal->save();
        $deal->offer->log(OfferEventType::Updated, $by, $diff);
    }
}
