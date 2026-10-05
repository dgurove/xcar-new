<?php

namespace App\Billing\Listeners;

use App\Billing\Acquiring\Actions\EnsurePayLink;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Счёт выставлен, оплачен частью или оплату отменили — у него снова есть ссылка на остаток (`EnsurePayLink`).
 * Сбой ссылки счёт не роняет: выставление уже случилось, ссылку можно завести из карточки.
 */
final class KeepPayLink
{
    public function __construct(private EnsurePayLink $ensure) {}

    public function handle(InvoiceIssued|PaymentRecorded|PaymentVoided $e): void
    {
        $invoice = $e instanceof PaymentVoided ? $e->payment->invoice : $e->invoice;
        try {
            ($this->ensure)($invoice);
        } catch (Throwable $ex) {
            Log::warning('acquiring: ссылка к счёту '.$invoice->id.' — '.$ex->getMessage());
        }
    }
}
