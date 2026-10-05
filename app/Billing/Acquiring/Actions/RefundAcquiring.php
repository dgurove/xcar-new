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
        // Сбой между ответом провайдера и нашей записью — повтор «Вернуть» идёт с тем же ключом (`refunded` не сдвинулся),
        // и ЮKassa отдаёт тот же возврат, а не второй.
        $refund = $this->gateway->refund($attempt, $amount);

        return DB::transaction(function () use ($attempt, $by, $amount, $surplus, $refund) {
            $attempt = AcquiringPayment::whereKey($attempt->id)->lockForUpdate()->with('payment', 'link')->firstOrFail();
            $attempt->update(['refunded' => round($attempt->refunded + $amount, 2), 'refund_id' => $refund['id'], 'refund_status' => $refund['status']]);
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
