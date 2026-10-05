<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;

/**
 * Статус возврата от провайдера (уведомление `refund.*` или опрос) — в попытку: «в обработке» не значит «вернули».
 * Деньги в счёте мы списали при «Вернуть»; отклонённый провайдером возврат виден в CRM словами у оплаты.
 *
 * @param  array{id: string, status: string, payment_id?: string}  $refund
 */
final class SettleRefund
{
    public function __invoke(array $refund): ?AcquiringPayment
    {
        $attempt = AcquiringPayment::where('refund_id', $refund['id'])->first()
            ?? (filled($refund['payment_id'] ?? null) ? AcquiringPayment::where('external_id', $refund['payment_id'])->first() : null);
        if ($attempt && ($attempt->refund_id === null || $attempt->refund_id === $refund['id'])) {
            $attempt->update(['refund_id' => $refund['id'], 'refund_status' => $refund['status']]);
        }

        return $attempt;
    }
}
