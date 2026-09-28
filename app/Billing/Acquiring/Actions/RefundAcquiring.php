<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\VoidPayment;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Вернуть деньги по ссылке: сначала у провайдера (чек возврата пробьёт он), потом у нас. Целиком — оплата
 * отменяется, счёт снова ждёт денег, ссылка гаснет. Только переплату (`$surplus`) — оплата счёта остаётся.
 */
final class RefundAcquiring
{
    public function __construct(private Gateway $gateway, private VoidPayment $void) {}

    public function __invoke(AcquiringPayment $attempt, User $by, bool $surplus = false): AcquiringPayment
    {
        $amount = $surplus ? $attempt->overpaid() : $attempt->refundable();
        if ($amount <= 0) {
            throw ValidationException::withMessages(['refund' => 'Возвращать нечего']);
        }
        $this->gateway->refund($attempt, $amount);

        return DB::transaction(function () use ($attempt, $by, $amount, $surplus) {
            $attempt = AcquiringPayment::whereKey($attempt->id)->lockForUpdate()->with('payment', 'link')->firstOrFail();
            $attempt->update(['refunded' => round($attempt->refunded + $amount, 2)]);
            if ($surplus) {
                return $attempt;
            }
            if ($attempt->payment && ! $attempt->payment->voided_at) {
                ($this->void)($attempt->payment, $by, 'Возврат по ссылке');
            }
            if ($attempt->link->state === PayLinkState::Paid && $attempt->link->payment_id === $attempt->payment_id) {
                $attempt->link->update(['state' => PayLinkState::Canceled, 'canceled_at' => now()]);
            }

            return $attempt;
        });
    }
}
