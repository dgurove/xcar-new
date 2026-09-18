<?php

namespace App\Billing\Actions;

use App\Billing\Events\PaymentRecorded;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Park\EventType;
use App\Support\Money;
use App\Support\Nav;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Оплата частями; не больше остатка; при нуле остатка счёт оплачен. */
final class RecordPayment
{
    public function __invoke(Invoice $invoice, User $by, float $amount, ?CarbonInterface $at = null, PaymentSource $source = PaymentSource::Bank, ?string $ref = null, ?string $note = null): Payment
    {
        Nav::forgetStaffCounts();
        $payment = DB::transaction(function () use ($invoice, $by, $amount, $at, $source, $ref, $note) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->state !== InvoiceState::Issued) {
                throw ValidationException::withMessages(['amount' => 'Счёт '.mb_strtolower($invoice->state->label())]);
            }
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $invoice->remaining() + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Не больше остатка '.Money::rub($invoice->remaining())]);
            }
            $payment = Payment::create(['invoice_id' => $invoice->id, 'party_id' => $invoice->party_id, 'amount' => $amount, 'paid_at' => Carbon::instance($at ?? now())->toDateString(), 'source' => $source, 'ref' => $ref, 'note' => $note, 'created_by' => $by->id]);
            $paid = round($invoice->paid + $amount, 2);
            $invoice->update(['paid' => $paid] + ($paid >= $invoice->total - 0.005 ? ['state' => InvoiceState::Paid, 'paid_at' => $payment->paid_at] : []));
            $invoice->vehicle?->log(EventType::Paid, $by, ['label' => $invoice->label(), 'amount' => $amount, 'left' => $invoice->fresh()->remaining()]);

            return $payment;
        });
        PaymentRecorded::dispatch($invoice->fresh(), $by);

        return $payment;
    }
}
