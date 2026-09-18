<?php

namespace App\Billing\Actions;

use App\Billing\Events\InvoiceOverdue;
use App\Billing\Invoice;
use App\Billing\InvoiceState;

/** Просрочка помечается раз, напоминание владельцу повторяется раз в неделю, пока не оплачено. */
final class TickInvoices
{
    public function __invoke(): int
    {
        $n = 0;
        $due = Invoice::where('state', InvoiceState::Issued)->whereDate('due_at', '<', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('overdue_at')->orWhere('reminded_at', '<', now()->subDays(7)))->get();
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
