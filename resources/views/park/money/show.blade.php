{{-- Карточка счёта стоянки — общая billing.invoice-page с адресами стоянки; заявки менеджеров решаются в CRM. --}}
@php
    $me = auth()->user();
    // Изменилось после отправки — исправленный живым документом (`/money/draft?…&replaces=`); оплаченный не правится.
    $drift = \App\Billing\Closing::drift($invoice);
    $group = \App\Billing\Closing::groupOfInvoice($invoice);
    $reviseUrl = $drift && $group && $invoice->paid == 0 && $me->canManagePark() ? '/money/closing/i'.$invoice->id : null;
@endphp
@include('billing.invoice-page', [
    'base' => '/money/invoices/'.$invoice->id, 'back' => ['Оплаты', '/money'], 'canManage' => $me->canManagePark(),
    'partiesUrl' => $me->canManagePark() ? '/money/parties' : null, 'dealUrl' => \App\Support\Surface::Crm->url('/work/deals/'.$invoice->deal_id),
    'drift' => $drift, 'reviseUrl' => $reviseUrl,
    // Файлы документа месяца — строками наверху (просмотр и «Скачать»), как в карточке «Счетов и актов».
    'files' => $invoice->direction === 'issued' && ($invoice->isRegistry() || $invoice->kind === \App\Billing\ChargeKind::Storage) ? \App\Billing\ClosingDoc::find('i'.$invoice->id)?->files() : null,
    'mailUrl' => !$invoice->isOwed() && $invoice->state === \App\Billing\InvoiceState::Issued && ($invoice->vehicle || $invoice->isRegistry()) && $me->canManagePark() ? '/mail/new?invoice='.$invoice->id : null,
])
