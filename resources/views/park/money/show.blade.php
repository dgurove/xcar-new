{{-- Карточка счёта стоянки — общая billing.invoice-page с адресами стоянки; заявки менеджеров решаются в CRM. --}}
@php $me = auth()->user(); @endphp
@include('billing.invoice-page', [
    'base' => '/money/invoices/'.$invoice->id, 'back' => ['Деньги', '/money'], 'canManage' => $me->canManagePark(),
    'partiesUrl' => '/money/parties', 'dealUrl' => \App\Support\Surface::Crm->url('/work/deals/'.$invoice->deal_id),
    'mailUrl' => !$invoice->isOwed() && $invoice->state === \App\Billing\InvoiceState::Issued && $invoice->vehicle && $me->canManagePark() ? '/mail/new?invoice='.$invoice->id : null,
])
