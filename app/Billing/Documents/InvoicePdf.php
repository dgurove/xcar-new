<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Support\Pdf;
use App\Support\TempFile;

/** Счёт на оплату — PDF на закрытом диске: снимок в момент выставления, реквизиты потом не меняют документ. */
final class InvoicePdf
{
    public function attach(Invoice $invoice): void
    {
        $path = TempFile::path('schet-', 'pdf');
        file_put_contents($path, $this->render($invoice));
        $invoice->addMedia($path)->usingFileName(($invoice->code ? 'Счет '.$invoice->fileNo() : 'schet-'.$invoice->number.'-'.$invoice->year).'.pdf')->sanitizingFileName(fn ($n) => str_replace(['/', '\\'], '-', $n))->toMediaCollection('file');
    }

    public function render(Invoice $invoice): string
    {
        return Pdf::render('billing.docs.invoice', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }
}
