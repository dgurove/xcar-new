<?php

namespace App\Billing\Documents;

use App\Billing\Invoice;
use App\Support\Pdf;

/**
 * Счёт на оплату — рисуется при каждом открытии из текущих данных (17.11.2026, владелец по 200881/2025: паспорт поправили, а в
 * счёте старый — «счёт вообще не должен храниться, как и договор, он должен формироваться из данных системы в момент, когда по
 * нему нажимают»). Файла у счёта нет: плательщик — `party_id`, реквизиты берутся у него сейчас; номер, строки и даты — свои у счёта.
 */
final class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['party', 'vehicle', 'charges', 'payments', 'deal.offer']);

        return Pdf::render('billing.docs.invoice', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }

    /** Имя файла, как было у сохранённого: «Счет П-12-2026.pdf» у счетов парковки с кодом, иначе «schet-217-2026.pdf». */
    public static function fileName(Invoice $invoice): string
    {
        return str_replace(['/', '\\'], '-', ($invoice->code ? 'Счет '.$invoice->fileNo() : 'schet-'.$invoice->number.'-'.$invoice->year).'.pdf');
    }

    /** Ответ «открыть PDF» — одна дверь CRM, парковки, кабинета и гаража. */
    public function response(Invoice $invoice)
    {
        $name = self::fileName($invoice);

        return response($this->render($invoice), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"".preg_replace('/[^\x20-\x7e]/', '_', $name)."\"; filename*=UTF-8''".rawurlencode($name),
            'Cache-Control' => 'private, no-store']);
    }
}
