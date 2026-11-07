<?php

namespace App\Offers\Actions;

use App\Live\RefreshDeal;
use App\Offers\Deal;
use App\Offers\Parallels;
use App\Workflow\Asks;

/**
 * Данные покупателя у параллельной сделки готовы (`AdvanceOnBuyerData` → сюда, 07.11.2026): её «Нет реквизитов» проходит
 * сам — теневой шаг на «Письмо с реквизитами», машина стоит; сотрудникам — «прислал реквизиты» (`Parallels::enter`).
 * Данные раньше шага (заполнил заранее) — шаг пройдёт, когда до него дойдёт.
 */
final class ParallelBuyerReady
{
    public function __invoke(Deal $deal): bool
    {
        $stage = $deal->shadowStage;
        if (! $stage || $stage->asks !== Asks::Buyer || ! Parallels::ready($deal) || ! ($next = Parallels::requisitesStage($stage, $deal))) {
            RefreshDeal::of($deal);

            return false;
        }
        Parallels::enter($deal, $next, $deal->buyer, 'Данные покупателя заполнены');

        return true;
    }
}
