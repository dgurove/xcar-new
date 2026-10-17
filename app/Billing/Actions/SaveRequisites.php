<?php

namespace App\Billing\Actions;

use App\Billing\Party;
use App\Users\User;

/**
 * Реквизиты человека для счетов и выплат — одна дверь: менеджер в кабинете, сотрудник в CRM, логист и админ в логистике.
 * Контрагент заводится по первому сохранению (`Party::forUser`); номер карты чистит сама модель.
 */
final class SaveRequisites
{
    /** @param array<string, mixed> $data поля `PartyRules::rules()` (и `vat_on_top`, где его задаёт сотрудник) */
    public function __invoke(User $user, array $data): Party
    {
        $party = Party::forUser($user);
        $party->update($data);

        return $party;
    }
}
