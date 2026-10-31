<?php

namespace App\Billing\Console;

use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use Illuminate\Console\Command;
use Throwable;

/**
 * Пересобрать PDF выставленных счетов новой вёрсткой (31.10.2026, владелец: «пересобрать все» — и оплаченные, и
 * аннулированные). Данные — нынешние: строки, суммы и номер у счёта, реквизиты сторон из `billing_parties`.
 */
class RerenderInvoices extends Command
{
    protected $signature = 'billing:rerender-invoices {--id=* : только эти счета}';

    protected $description = 'Пересобрать PDF выставленных счетов';

    public function handle(InvoicePdf $pdf): int
    {
        $done = $failed = 0;
        Invoice::withoutGlobalScopes()->where('direction', 'issued')->whereNotNull('number')
            ->when($this->option('id'), fn ($q, $ids) => $q->whereKey($ids))
            ->orderBy('id')->chunkById(50, function ($invoices) use ($pdf, &$done, &$failed) {
                foreach ($invoices as $invoice) {
                    try {
                        $pdf->attach($invoice);
                        $done++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("счёт {$invoice->id}: {$e->getMessage()}");
                    }
                }
            });
        $this->info("пересобрано: {$done}, ошибок: {$failed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
