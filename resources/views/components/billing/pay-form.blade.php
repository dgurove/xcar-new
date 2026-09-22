{{-- Оплата или перечисление: дата, сумма (по умолчанию остаток), источник, номер платёжки, её скан и заметка.
     action — куда: на стоянке /money/invoices/{id}/payments, в CRM /work/money/invoices/{id}/payments. --}}
@props(['invoice', 'action', 'sources' => \App\Billing\PaymentSource::options()])
@php use App\Support\Money; @endphp
<form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-3" data-turbo-confirm="{{ $invoice->isOwed() ? 'Перечислено' : 'Оплачено' }} {{ Money::rub($invoice->remaining()) }}?">
    @csrf
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="amount" label="Сумма" :value="rtrim(rtrim(number_format($invoice->remaining(), 2, '.', ''), '0'), '.')" inputmode="decimal" required/>
        <x-ui.field name="paid_at" label="Дата" type="date" :value="now()->toDateString()"/>
        <x-ui.field name="source" label="Откуда" :options="$sources" value="bank"/>
        <x-ui.field name="ref" label="№ платёжки"/>
        <x-ui.field name="slip" label="Платёжка" type="file" accept=".pdf,.jpg,.jpeg,.png,.heic" span="col-span-2"/>
        <x-ui.field name="note" label="Заметка" span="col-span-2"/>
    </div>
    <x-ui.button block>{{ $invoice->isOwed() ? 'Перечислено' : 'Оплачен' }}</x-ui.button>
</form>
