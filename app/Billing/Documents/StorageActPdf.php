<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Billing\Party;
use App\Support\Pdf;

/** Акт оказанных услуг хранения — PDF при счёте: закрытие месяца кладёт его рядом со счётом, письмо вендору берёт оба. */
final class StorageActPdf
{
    public function attach(Invoice $invoice): void
    {
        $path = tempnam(sys_get_temp_dir(), 'akt-').'.pdf';
        file_put_contents($path, $this->render($invoice));
        $invoice->addMedia($path)->usingFileName('akt-hraneniya-'.$invoice->number.'-'.$invoice->year.'.pdf')->toMediaCollection('act');
    }

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges']);

        return Pdf::render('billing.docs.storage-act', ['invoice' => $invoice, 'self' => Party::self()]);
    }
}
