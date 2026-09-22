<?php

namespace App\Billing\Actions;

use App\Billing\Events\PaymentRejected;
use App\Billing\Payment;
use App\Billing\PaymentState;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Заявленная оплата не поступила: строка остаётся с причиной, менеджер видит и заявляет заново. */
final class RejectPayment
{
    public function __invoke(Payment $claim, User $by, ?string $reason = null): Payment
    {
        if ($claim->state !== PaymentState::Claimed) {
            throw ValidationException::withMessages(['payment' => 'Оплата уже '.mb_strtolower($claim->state->label())]);
        }
        Nav::forgetStaffCounts();
        $claim->update(['state' => PaymentState::Rejected, 'reject_reason' => $reason, 'decided_at' => now()]);
        PaymentRejected::dispatch($claim, $by);

        return $claim;
    }
}
