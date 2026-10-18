<?php

namespace App\Billing\Actions;

use App\Billing\Events\InvoiceOverdue;
use App\Billing\Invoice;
use App\Billing\InvoiceState;

/** Просрочка помечается раз (еженедельных напоминаний в Telegram нет с 18.10.2026: владелец — «сводки бесполезные»). */
final class TickInvoices
{
    public function __invoke(): int
    {
        $n = 0;
        $due = Invoice::where('state', InvoiceState::Issued)->whereDate('due_at', '<', now()->toDateString())
            ->whereNull('overdue_at')->get();
        foreach ($due as $invoice) {
            if ($invoice->remaining() <= 0) {
                continue;
            }
            $invoice->forceFill(['overdue_at' => $invoice->overdue_at ?? now(), 'reminded_at' => now()])->save();
            InvoiceOverdue::dispatch($invoice);
            $n++;
        }

        return $n;
    }
}
