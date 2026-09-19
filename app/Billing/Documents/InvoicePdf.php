<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Billing\Party;
use App\Support\Pdf;

/** Счёт на оплату — PDF на закрытом диске: снимок в момент выставления, реквизиты потом не меняют документ. */
final class InvoicePdf
{
    public function attach(Invoice $invoice): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schet-').'.pdf';
        file_put_contents($path, $this->render($invoice));
        $invoice->addMedia($path)->usingFileName('schet-'.$invoice->number.'-'.$invoice->year.'.pdf')->toMediaCollection('file');
    }

    public function render(Invoice $invoice): string
    {
        return Pdf::render('billing.docs.invoice', ['invoice' => $invoice, 'self' => Party::self()]);
    }
}
