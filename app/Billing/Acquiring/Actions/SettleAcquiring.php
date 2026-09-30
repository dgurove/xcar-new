<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Checkout;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Acquiring\PayMethod;
use App\Billing\Actions\RecordPayment;
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
 */
final class SettleAcquiring
{
    public function __construct(private Gateway $gateway, private RecordPayment $record) {}

    public function __invoke(AcquiringPayment $attempt, ?Checkout $fresh = null): AcquiringPayment
    {
        $fresh ??= $this->gateway->fetch($attempt->external_id);
        $paid = null;
        $attempt = DB::transaction(function () use ($attempt, $fresh, &$paid) {
            $attempt = AcquiringPayment::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $attempt->update([
                'status' => $fresh->status, 'income_amount' => $fresh->income, 'method' => $fresh->method ?? $attempt->method,
                'receipt_status' => $fresh->receipt, 'payload' => $fresh->raw, 'checked_at' => now(),
            ]);
            if ($fresh->status !== 'succeeded' || $attempt->payment_id) {
                return $attempt;
            }
            $link = $attempt->link()->lockForUpdate()->first();
            $invoice = Invoice::withoutGlobalScope('demo')->find($link->invoice_id);
            $amount = round(min($fresh->amount, $invoice->remaining()), 2);
            if ($invoice->state !== InvoiceState::Issued || $amount <= 0) {
                return $attempt;
            }
            $payment = ($this->record)($invoice, Robot::user(), $amount, now(), PaymentSource::Acquiring, $attempt->external_id,
                'По ссылке '.PayMethod::label($fresh->method).', платил '.$link->payerLabel());
            $attempt->update(['payment_id' => $payment->id, 'applied' => $amount]);
            $link->update(['state' => PayLinkState::Paid, 'paid_at' => now(), 'payment_id' => $payment->id]);
            $paid = [$link, $payment];

            return $attempt;
        });
        if ($paid) {
            PaidOnline::dispatch(...$paid);
        }

        return $attempt;
    }
}
