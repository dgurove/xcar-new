<?php

namespace App\Offers\Actions;

use App\Billing\Robot;
use App\Offers\Deal;
use App\Users\User;

/**
 * Сделка прошла согласование: поставщик сказал «да», а где спрашиваем менеджера — тот нажал «Покупаю» (09.10.2026,
 * владелец: Каркаде ещё не подтвердил, а менеджеру уже «Оплатите»). Только теперь у неё встают счета и ссылка
 * (`SyncDealInvoices`). Зовёт `EnterStage` при первом входе на этап не из согласования (`Stage::isAgreement`), а без
 * маршрута — сразу принятие. Совкомбанк поставщика не спрашивает: первый же этап — счёт.
 */
final class AgreeDeal
{
    public function __construct(private SyncDealInvoices $invoices) {}

    public function __invoke(Deal $deal, ?User $by): void
    {
        if ($deal->agreed() || ! $deal->isActive()) {
            return;
        }
        $deal->forceFill(['agreed_at' => now()])->save();
        ($this->invoices)($deal, $by ?? Robot::user());
    }
}
