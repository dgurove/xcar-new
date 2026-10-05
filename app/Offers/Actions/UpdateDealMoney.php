<?php

namespace App\Offers\Actions;

use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Поправить деньги сделки: схему оплаты, сумму собственнику или страховой, вознаграждение, режим, гаражной — нашу долю.
 * Пока за наши счета не платили (`Deal::moneyEditable`): счета, что ставятся сами, перевыставит `SyncDealInvoices`.
 */
final class UpdateDealMoney
{
    public function __construct(private SyncDealInvoices $invoices) {}

    public function __invoke(Deal $deal, User $by, ?int $commission, CommissionMode $mode, ?DealScheme $scheme = null, ?int $ownerPrice = null, ?int $share = null): void
    {
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['commission' => 'По счёту сделки уже платили — сначала отмените оплату']);
        }
        if ($commission !== null && $deal->amount !== null && $commission > $deal->amount) {
            throw ValidationException::withMessages(['commission' => 'Не больше цены подтверждения '.Money::rub($deal->amount)]);
        }
        $scheme ??= $deal->schemeOf();
        $selection = $scheme->paysSelection() && ! $deal->isGarage();
        $wasSelection = $deal->paysSelection();
        DB::transaction(function () use ($deal, $by, $commission, $mode, $scheme, $selection, $wasSelection, $ownerPrice, $share) {
            $deal->update($deal->isGarage()
                ? ['scheme' => $scheme, 'share' => $deal->isGarageManager() ? $share : null]
                : ['commission' => $commission, 'commission_mode' => $selection ? CommissionMode::Withheld : $mode, 'scheme' => $scheme,
                    'owner_price' => $selection ? ($ownerPrice ?? $deal->owner_price ?? $deal->cost) : null]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => match (true) {
                $deal->isGarage() => 'Наша доля: '.($deal->share ? Money::rub($deal->share) : 'нет'),
                $selection => $scheme->payeeLabel().' '.Money::rub((int) $deal->ownerPrice()).', вознаграждение '.($commission ? Money::rub($commission) : 'нет'),
                default => 'ПРАЙМ по счёту, вознаграждение '.($commission ? Money::rub($commission) : 'нет').', '.mb_strtolower($deal->commission_mode->label()),
            }]);
            // Ушли со схемы, где менеджер платит подбор, — неоплаченный подбор гаснет (ссылка с ним).
            if ($wasSelection && ! $deal->fresh()->paysSelection()) {
                foreach ($deal->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Selection)->where('state', \App\Billing\InvoiceState::Issued)->get() as $old) {
                    if ($old->paidMoney() == 0) {
                        app(\App\Billing\Actions\VoidInvoice::class)($old, $by, 'Схема оплаты изменена');
                    }
                }
            }
            ($this->invoices)($deal->fresh(), $by);
        });
    }
}
