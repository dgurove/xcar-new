<?php

namespace App\Billing\Documents;

use App\Billing\Acts\RegistryAct;
use App\Billing\Invoice;
use App\Support\Pdf;
use App\Support\TempFile;

/**
 * Акт к счёту хранения, кладётся рядом со счётом; письмо вендору берёт оба. Реестр закрытия месяца — прежний файл
 * страховой (`Acts\RegistryAct`: Excel или Word по форме вендора); счёт на одну ТС — PDF акта хранения.
 */
final class StorageActPdf
{
    public function __construct(private RegistryAct $registry) {}

    public function attach(Invoice $invoice): void
    {
        if ($invoice->isRegistry()) {
            $this->registry->attach($invoice);

            return;
        }
        $path = TempFile::path('akt-', 'pdf');
        file_put_contents($path, $this->render($invoice));
        $invoice->addMedia($path)->usingFileName('akt-hraneniya-'.$invoice->docNo().'-'.$invoice->year.'.pdf')->toMediaCollection('act');
    }

    /** PDF акта хранения одной ТС. */
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['party', 'vehicle', 'vehicle.yard', 'charges']);

        return Pdf::render('billing.docs.storage-act', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }
}
