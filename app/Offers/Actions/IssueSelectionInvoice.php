<?php

namespace App\Offers\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Offers\Deal;
use App\Users\User;

/** Счёт «Подбор ТС» сделки — теперь часть `SyncDealInvoices` (схемы оплаты, 05.10.2026); дверь осталась для миграций Kuga. */
final class IssueSelectionInvoice
{
    public function __construct(private SyncDealInvoices $sync) {}

    public function __invoke(Deal $deal, User $by): ?Invoice
    {
        ($this->sync)($deal, $by);

        return $deal->issuedInvoices()->where('kind', ChargeKind::Selection)->latest('id')->first();
    }
}
