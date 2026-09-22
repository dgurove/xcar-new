{{-- Форма оплаты стоянки — общая x-billing.pay-form с адресом стоянки. --}}
<x-billing.pay-form :invoice="$invoice" :action="'/money/invoices/'.$invoice->id.'/payments'" :sources="$sources"/>
