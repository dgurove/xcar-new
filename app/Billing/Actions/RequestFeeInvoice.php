<?php

namespace App\Billing\Actions;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Numbering;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Запросить счёт…» у комиссии (18.11.2026, владелец: «счёт на комиссию мы больше не формируем, только по ссылке либо наличкой»;
 * «ОООЧЕНЬ редко просят счёт» — у менеджера «Запросить счёт…», в CRM «Запросили счёт…» с предупреждением про договор и акт).
 * Счёт подбора ПРАЙМ становится документом (`Invoice::isDocument`): номер — сразу, если менеджер его уже видит, иначе при
 * отправке (`Numbering::assign`), PDF и «Счёт № N» — у менеджера и в CRM. Перевыставленный счёт отметку несёт дальше
 * (`SyncDealInvoices::slot`).
 */
final class RequestFeeInvoice
{
    public function __invoke(Invoice $invoice, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! Invoice::feeByLink($invoice->kind, $invoice->seller) || $invoice->state !== InvoiceState::Issued || $invoice->direction !== 'issued') {
                throw ValidationException::withMessages(['paper' => 'Счёт на эту оплату уже не выставить']);
            }
            if ($invoice->requested_at) {
                return $invoice;
            }
            $invoice->forceFill(['requested_at' => now(), 'requested_by' => $by->id])->save();
            if ($invoice->isShownToManager()) {
                Numbering::assign($invoice);
            }
            $invoice->refresh();
            ($invoice->deal?->offer ?? $invoice->offer)?->log(OfferEventType::Note, $by, ['text' => 'Запросили счёт за подбор'.($invoice->number ? ' '.$invoice->label() : '')]);
            $invoice->refreshLists();

            return $invoice;
        });
    }
}
