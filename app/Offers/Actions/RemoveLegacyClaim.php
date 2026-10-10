<?php

namespace App\Offers\Actions;

use App\Offers\LegacyClaim;
use App\Offers\Offer;

/** Убрать номер из «Таблицы Кати»: черновик, если заведён, остаётся в своей рабочей вкладке. Отдаёт его предложение. */
final class RemoveLegacyClaim
{
    public function __invoke(LegacyClaim $claim): ?Offer
    {
        $offer = $claim->offer();
        $claim->delete();

        return $offer;
    }
}
