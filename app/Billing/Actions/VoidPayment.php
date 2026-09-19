<?php

namespace App\Billing\Actions;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Отменить оплату: остаток растёт, оплаченный счёт снова выставлен. */
final class VoidPayment
{
    public function __invoke(Payment $payment, User $by, ?string $reason = null): Payment
    {
        Nav::forgetStaffCounts();

        return DB::transaction(function () use ($payment, $reason) {
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->voided_at) {
                throw ValidationException::withMessages(['payment' => 'Оплата уже отменена']);
            }
            $payment->update(['voided_at' => now(), 'note' => trim(($payment->note ? $payment->note.'. ' : '').(string) $reason) ?: null]);
            $invoice->update(['paid' => round(max(0, $invoice->paid - $payment->amount), 2), 'state' => InvoiceState::Issued, 'paid_at' => null]);

            return $payment;
        });
    }
}
