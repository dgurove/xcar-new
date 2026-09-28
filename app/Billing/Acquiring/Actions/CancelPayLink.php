<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Users\User;

/** Погасить открытую ссылку: страница скажет «Ссылка отменена». Незавершённые попытки у провайдера сами истекут. */
final class CancelPayLink
{
    public function __invoke(PayLink $link, ?User $by = null): PayLink
    {
        if ($link->isOpen()) {
            $link->update(['state' => PayLinkState::Canceled, 'canceled_at' => now()]);
        }

        return $link;
    }
}
