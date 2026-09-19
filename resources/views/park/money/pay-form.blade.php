{{-- Оплата: дата, сумма (по умолчанию остаток), источник, номер платёжки, её скан и заметка. Для «мы должны» — «Перечислено». --}}
@php use App\Support\Money; @endphp
<form method="post" action="/money/invoices/{{ $invoice->id }}/payments" enctype="multipart/form-data" class="flex flex-col gap-3" data-turbo-confirm="{{ $invoice->isOwed() ? 'Перечислено' : 'Оплачено' }} {{ Money::rub($invoice->remaining()) }}?">
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
