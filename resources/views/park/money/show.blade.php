{{-- Карточка счёта стоянки — общая billing.invoice-page с адресами стоянки; заявки менеджеров решаются в CRM. --}}
@php
    $me = auth()->user();
    // Изменилось после отправки — исправленный живым документом (`/money/draft?…&replaces=`); оплаченный не правится.
    $drift = \App\Billing\Closing::drift($invoice);
    $group = \App\Billing\Closing::groupOfInvoice($invoice);
    $reviseUrl = $drift && $group && $invoice->paid == 0 && $me->canManagePark() ? '/money/draft?'.http_build_query(['month' => $invoice->month->format('Y-m'), 'group' => $group, 'replaces' => $invoice->id]) : null;
@endphp
@include('billing.invoice-page', [
    'base' => '/money/invoices/'.$invoice->id, 'back' => ['Оплаты', '/money'], 'canManage' => $me->canManagePark(),
    'partiesUrl' => $me->canManagePark() ? '/money/parties' : null, 'dealUrl' => \App\Support\Surface::Crm->url('/work/deals/'.$invoice->deal_id),
    'drift' => $drift, 'reviseUrl' => $reviseUrl,
    'mailUrl' => !$invoice->isOwed() && $invoice->state === \App\Billing\InvoiceState::Issued && ($invoice->vehicle || $invoice->isRegistry()) && $me->canManagePark() ? '/mail/new?invoice='.$invoice->id : null,
])
