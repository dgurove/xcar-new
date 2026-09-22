<?php

namespace App\Offers\Actions;

use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Поправить вознаграждение и режим, пока по сделке нет живого счёта: строка счёта уже напечатана — сначала аннулировать его. */
final class UpdateDealMoney
{
    public function __invoke(Deal $deal, User $by, ?int $commission, CommissionMode $mode): void
    {
        if (! $deal->commissionEditable()) {
            throw ValidationException::withMessages(['commission' => 'По сделке уже выставлен счёт — сначала аннулируйте его']);
        }
        if ($commission !== null && $commission > $deal->amount) {
            throw ValidationException::withMessages(['commission' => 'Не больше цены подтверждения '.Money::rub($deal->amount)]);
        }
        $deal->update(['commission' => $commission, 'commission_mode' => $mode]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Агентское вознаграждение: '.($commission ? Money::rub($commission) : 'нет').', '.mb_strtolower($mode->label())]);
    }
}
