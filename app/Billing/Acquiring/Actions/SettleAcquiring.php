<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Checkout;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Acquiring\PayMethod;
use App\Billing\Actions\RecordPayment;
use App\Billing\Events\OnlinePaymentTrouble;
use App\Billing\Events\PaidOnline;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Billing\Robot;
use Illuminate\Support\Facades\DB;

/**
 * Свести попытку с провайдером: статус берём только у него (уведомлению не верим — перечитываем по API).
 * Успешная и ещё не учтённая — оплата счёта от имени робота, источник «Эквайринг», ссылка оплачена.
 * Повтор уведомления или опроса ничего не задвоит: попытка под замком, учтённая пропускается.
 * Счёт закрыли раньше иначе, целиком или частью, — в счёт ложится остаток, остальное — переплата к возврату.
 * Перемены, о которых должен узнать человек, — `OnlinePaymentTrouble`: банк отклонил (бросил оплату — не повод),
 * переплата, чек не пробился. Каждая — один раз: по переходу статуса, а не по самому статусу.
 */
final class SettleAcquiring
{
    public function __construct(private Gateway $gateway, private RecordPayment $record, private EnsurePayLink $ensure) {}

    public function __invoke(AcquiringPayment $attempt, ?Checkout $fresh = null): AcquiringPayment
    {
        $fresh ??= $this->gateway->fetch($attempt->external_id);
        $paid = null;
        $trouble = [];
        $attempt = DB::transaction(function () use ($attempt, $fresh, &$paid, &$trouble) {
            $attempt = AcquiringPayment::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $was = $attempt->only(['status', 'receipt_status']);
            $attempt->update([
                'status' => $fresh->status, 'cancel_reason' => $fresh->cancelReason ?? $attempt->cancel_reason, 'income_amount' => $fresh->income,
                'method' => $fresh->method ?? $attempt->method, 'receipt_status' => $fresh->receipt, 'payload' => $fresh->raw, 'checked_at' => now(),
            ]);
            if ($was['status'] !== 'canceled' && $fresh->status === 'canceled' && ! in_array($fresh->cancelReason, ['expired_on_confirmation', 'canceled_by_merchant', null], true)) {
                $trouble[] = 'declined';
            }
            if ($was['receipt_status'] !== 'canceled' && $fresh->receipt === 'canceled') {
                $trouble[] = 'receipt';
            }
            if ($fresh->status !== 'succeeded' || $attempt->payment_id) {
                return $attempt;
            }
            $link = $attempt->link()->lockForUpdate()->first();
            $invoice = Invoice::withoutGlobalScope('demo')->find($link->invoice_id);
            $amount = round(min($fresh->amount, $invoice->remaining()), 2);
            if ($invoice->state !== InvoiceState::Issued || $amount <= 0) {
                // Счёт уже закрыт иначе: всё заплаченное — переплата. Отмечаем `applied` нулём один раз — повтор не позовёт снова.
                if ($was['status'] !== 'succeeded') {
                    $trouble[] = 'overpaid';
                }

                return $attempt;
            }
            $payment = ($this->record)($invoice, Robot::user(), $amount, now(), PaymentSource::Acquiring, $attempt->external_id,
                'По ссылке '.PayMethod::label($fresh->method).', платил '.$link->payerLabel());
            $attempt->update(['payment_id' => $payment->id, 'applied' => $amount]);
            $link->update(['state' => PayLinkState::Paid, 'paid_at' => now(), 'payment_id' => $payment->id]);
            $paid = [$link, $payment];
            if ($amount < $fresh->amount - 0.005) {
                $trouble[] = 'overpaid';
            }

            return $attempt;
        });
        if ($paid) {
            PaidOnline::dispatch(...$paid);
            // Заплатили часть (лимит одного платежа) — на остаток сразу следующая ссылка; `KeepPayLink` на оплату
            // сработал ещё внутри транзакции, когда эта ссылка была открыта.
            ($this->ensure)(Invoice::withoutGlobalScope('demo')->find($paid[1]->invoice_id));
        }
        foreach ($trouble as $what) {
            OnlinePaymentTrouble::dispatch($attempt, $what);
        }

        return $attempt;
    }
}
