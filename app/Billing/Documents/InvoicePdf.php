<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Billing\Party;
use Dompdf\Dompdf;
use Dompdf\Options;

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
        $dir = storage_path('app/private/dompdf');
        @mkdir($dir, 0775, true);
        $options = (new Options)->setIsRemoteEnabled(false)->setDefaultFont('Onest')->setDefaultPaperSize('a4')->setDefaultPaperOrientation('portrait')
            ->setTempDir($dir)->setFontCache($dir)->setChroot([$dir, resource_path('fonts/pdf'), public_path('images')]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('billing.docs.invoice', ['invoice' => $invoice, 'self' => Party::self(), 'pdf' => true])->render());
        $pdf->render();

        return $pdf->output();
    }
}
