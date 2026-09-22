<?php

namespace App\Billing\Actions;

use App\Billing\Events\PaymentClaimed;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Support\Money;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Менеджер сообщает об оплате: сумма, дата, платёжка. В `paid` не входит, пока
 * сотрудник не подтвердит; заявить можно не больше, чем остаток за вычетом уже заявленного.
 */
final class ClaimPayment
{
    public function __invoke(Invoice $invoice, User $by, float $amount, ?CarbonInterface $at, UploadedFile $slip, ?string $ref = null): Payment
    {
        Nav::forgetStaffCounts();
        $payment = DB::transaction(function () use ($invoice, $by, $amount, $at, $slip, $ref) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->state !== InvoiceState::Issued || $invoice->isOwed()) {
                throw ValidationException::withMessages(['amount' => 'Счёт '.mb_strtolower($invoice->state->label())]);
            }
            $left = round($invoice->remaining() - $invoice->claimed(), 2);
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $left + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Не больше '.Money::rub(max(0, $left))]);
            }
            $payment = Payment::create([
                'invoice_id' => $invoice->id, 'party_id' => $invoice->party_id, 'amount' => $amount, 'paid_at' => Carbon::instance($at ?? now())->toDateString(),
                'source' => PaymentSource::Bank, 'ref' => $ref, 'state' => PaymentState::Claimed, 'created_by' => $by->id,
            ]);
            $payment->addMedia($slip)->toMediaCollection('slip');

            return $payment;
        });
        PaymentClaimed::dispatch($payment, $by);

        return $payment;
    }
}
