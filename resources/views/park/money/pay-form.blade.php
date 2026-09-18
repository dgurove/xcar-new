{{-- Оплата: дата, сумма (по умолчанию остаток), источник, номер платёжки. Для «мы должны» — «Перечислено». --}}
@php use App\Support\Money; @endphp
<form method="post" action="/money/invoices/{{ $invoice->id }}/payments" class="flex flex-col gap-3" data-turbo-confirm="{{ $invoice->isOwed() ? 'Перечислено' : 'Оплачено' }} {{ Money::rub($invoice->remaining()) }}?">
    @csrf
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="amount" label="Сумма" :value="rtrim(rtrim(number_format($invoice->remaining(), 2, '.', ''), '0'), '.')" inputmode="decimal" required/>
        <x-ui.field name="paid_at" label="Дата" type="date" :value="now()->toDateString()"/>
        <x-ui.field name="source" label="Откуда" :options="$sources" value="bank"/>
        <x-ui.field name="ref" label="№ платёжки"/>
    </div>
    <x-ui.button block>{{ $invoice->isOwed() ? 'Перечислено' : 'Оплачен' }}</x-ui.button>
</form>
