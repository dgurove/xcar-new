{{-- Карточка счёта по сделке в CRM — общая billing.invoice-page; заявки менеджера решаются здесь. --}}
@include('billing.invoice-page', [
    'base' => '/work/money/invoices/'.$invoice->id, 'back' => ['Деньги', '/work/money'], 'canManage' => true, 'claims' => '/work/payments',
    'partiesUrl' => $invoice->deal?->buyer ? '/settings/users/'.$invoice->deal->buyer_id : null, 'dealUrl' => '/work/deals/'.$invoice->deal_id, 'mailUrl' => null,
])
