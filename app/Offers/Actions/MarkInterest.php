<?php

namespace App\Offers\Actions;

use App\Offers\Interest;
use App\Offers\InterestState;
use App\Users\User;

/** Интерес отработан: «Связались» или «Закрыт» — ставит сотрудник или менеджер покупателя. */
final class MarkInterest
{
    public function __invoke(Interest $interest, InterestState $state, User $by): Interest
    {
        if ($interest->state !== $state) {
            $interest->update(['state' => $state]);
        }

        return $interest;
    }
}
