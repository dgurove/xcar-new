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
 * Поправить деньги сделки: вознаграждение, режим, кому платят за ТС и сколько собственнику по ДКП. Пока счёта нет — как
 * угодно. Счёт по ДКП («Подбор ТС», выставлен при принятии) перевыставляется сам, пока за него не платили
 * (`IssueSelectionInvoice`); любой другой живой счёт — сначала аннулировать.
 */
final class UpdateDealMoney
{
    public function __construct(private IssueSelectionInvoice $selection) {}

    public function __invoke(Deal $deal, User $by, ?int $commission, CommissionMode $mode, ?DealScheme $scheme = null, ?int $ownerPrice = null): void
    {
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['commission' => 'По сделке уже выставлен счёт — сначала аннулируйте его']);
        }
        if ($commission !== null && $commission > $deal->amount) {
            throw ValidationException::withMessages(['commission' => 'Не больше цены подтверждения '.Money::rub($deal->amount)]);
        }
        $scheme ??= $deal->scheme ?? DealScheme::Ours;
        $dkp = $scheme === DealScheme::OwnerDkp;
        $wasDkp = $deal->isDkp();
        DB::transaction(function () use ($deal, $by, $commission, $mode, $scheme, $dkp, $wasDkp, $ownerPrice) {
            $deal->update(['commission' => $commission, 'commission_mode' => $dkp ? CommissionMode::Withheld : $mode, 'scheme' => $scheme,
                'owner_price' => $dkp ? ($ownerPrice ?? $deal->owner_price ?? $deal->cost) : null]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => $dkp
                ? 'По ДКП: собственнику '.Money::rub((int) $deal->ownerPrice()).', вознаграждение '.($commission ? Money::rub($commission) : 'нет')
                : 'Агентское вознаграждение: '.($commission ? Money::rub($commission) : 'нет').', '.mb_strtolower($mode->label())]);
            // Счёт за подбор — по новым деньгам; ушли с ДКП — неоплаченный гаснет.
            if ($dkp || $wasDkp) {
                ($this->selection)($deal->fresh(), $by);
            }
        });
    }
}
