<?php

namespace App\Billing\Actions;

use App\Billing\Events\PaymentConfirmed;
use App\Billing\Payment;
use App\Billing\PaymentState;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Сотрудник подтвердил, что заявленная менеджером оплата поступила: та же строка становится оплатой. */
final class ConfirmPayment
{
    public function __construct(private RecordPayment $record) {}

    public function __invoke(Payment $claim, User $by): Payment
    {
        if ($claim->state !== PaymentState::Claimed) {
            throw ValidationException::withMessages(['payment' => 'Оплата уже '.mb_strtolower($claim->state->label())]);
        }
        $payment = ($this->record)($claim->invoice, $by, $claim->amount, $claim->paid_at, $claim->source, $claim->ref, $claim->note, $claim);
        PaymentConfirmed::dispatch($payment, $by);

        return $payment;
    }
}
