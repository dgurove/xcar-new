{{-- Оплата, выплата менеджеру или перечисление вендору: дата, сумма (по умолчанию остаток), источник, номер платёжки, её скан и заметка.
     action — куда: на стоянке /money/invoices/{id}/payments, в CRM /work/money/invoices/{id}/payments. --}}
@props(['invoice', 'action', 'sources' => \App\Billing\PaymentSource::options()])
@php use App\Support\Money; $done = $invoice->isOwed() ? ($invoice->isAgentFee() ? 'Выплачено' : 'Перечислено') : null; @endphp
<form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-3" data-turbo-confirm="{{ $done ?? 'Оплачено' }} {{ Money::rub($invoice->remaining()) }}?">
    @csrf
    <div class="grid grid-cols-2 gap-3">
        {{-- Разрядами и с копейками, как в «Оплатить»: «15 000,50», а не «15000.5». --}}
        <x-ui.field name="amount" label="Сумма" :value="Money::nums($invoice->remaining(), fmod($invoice->remaining(), 1) ? 2 : 0)" required data-controller="digits" data-digits-decimals-value="2" data-action="input->digits#format"/>
        <x-ui.field name="paid_at" label="Дата" type="date" :value="now()->toDateString()"/>
        <x-ui.field name="source" label="Откуда" :options="$sources" value="bank"/>
        <x-ui.field name="ref" label="№ платёжки"/>
        <x-ui.file-field name="slip" label="Платёжка" accept=".pdf,.jpg,.jpeg,.png,.heic" span="col-span-2"/>
        <x-ui.field name="note" label="Заметка" span="col-span-2"/>
    </div>
    <x-ui.button block>{{ $done ?? 'Оплачен' }}</x-ui.button>
</form>
