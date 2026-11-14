<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use Illuminate\Support\Carbon;

/**
 * «Продано» во «Все» (`DealDates`, 14.11.2026): наши счета сделки оплачены целиком — `deals.paid_in_at` датой последней оплаты;
 * оплату отменили и долг снова есть — дата снимается. Вписанную руками (`SetDealDate`) раньше оплаты не трогаем.
 */
final class StampPaidIn
{
    public function handle(PaymentRecorded|PaymentVoided $e): void
    {
        $invoice = $e instanceof PaymentRecorded ? $e->invoice : $e->payment->invoice;
        $deal = $invoice?->deal_id ? Deal::find($invoice->deal_id) : null;
        if (! $deal || $invoice->direction !== 'issued') {
            return;
        }
        $paid = $deal->fullyPaid();
        if ($paid && ! $deal->paid_in_at) {
            $last = $deal->issuedInvoices()->get()->flatMap(fn ($i) => $i->payments()->where('state', 'confirmed')->pluck('paid_at'))->max();
            $at = $last ? Carbon::parse($last) : now();
            $deal->forceFill(['paid_in_at' => $at->isToday() ? now() : $at->setTime(12, 0)])->saveQuietly();
        } elseif (! $paid && $deal->paid_in_at && $e instanceof PaymentVoided && $invoice->fresh()->state === InvoiceState::Issued) {
            $deal->forceFill(['paid_in_at' => null])->saveQuietly();
        }
    }
}
