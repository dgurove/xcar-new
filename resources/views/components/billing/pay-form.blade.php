{{-- Оплата, выплата менеджеру или перечисление вендору: откуда (сегментом), сумма (по умолчанию остаток), дата, номер платёжки,
     её скан и заметка. action — куда: на стоянке /money/invoices/{id}/payments, в CRM /work/money/invoices/{id}/payments.
     07.10.2026: поля строками (.fields); в шторке (sheet) кнопка .sheet-foot, на стоянке форма в раскрывашке — без неё.
     13.10.2026: «Откуда» — сегмент вместо списка, «Зачёт» руками не выбрать (`PaymentSource::manual`); в шторке второго
     подтверждения нет — шторка сама осознанный шаг, кнопка называет исход («Деньги пришли»); вне шторки — «Записать оплату?».
     19.10.2026: поля по «Откуда» (reveal): банк — дата, № и скан платёжки, наличные — только когда, эквайринг — дата
     и № операции. Платёжку у наличных не спрашиваем (владелец). --}}
@props(['invoice', 'action', 'sources' => \App\Billing\PaymentSource::manual(), 'sheet' => false, 'source' => null])
@php
    use App\Support\Money;
    $done = $invoice->isOwed() ? ($invoice->isAgentFee() ? 'Выплачено' : 'Перечислено') : ($sheet ? 'Деньги пришли' : 'Оплачен');
    // `source` — откуда по умолчанию: подтверждаем заявку менеджера («оплатил наличными») — её способом (28.10.2026).
    $source = old('source', $source && array_key_exists($source, $sources) ? $source : (array_key_first($sources) ?? 'bank'));
@endphp
<form method="post" action="{{ $action }}" enctype="multipart/form-data" class="flex flex-col gap-3" data-controller="reveal" @unless ($sheet) data-turbo-confirm="{{ $invoice->isOwed() ? 'Записать выплату?' : 'Записать оплату?' }}" data-turbo-confirm-label="{{ $done }}" @endunless>
    @csrf
    <div class="segment">
        @foreach ($sources as $value => $label)
            <label><input type="radio" name="source" value="{{ $value }}" @checked($source === $value) data-action="reveal#pick"><span>{{ $label }}</span></label>
        @endforeach
    </div>
    <div class="fields">
        {{-- Разрядами и с копейками, как в «Оплатить»: «15 000,50», а не «15000.5». --}}
        <x-ui.field name="amount" label="Сумма" :value="Money::nums($invoice->remaining(), fmod($invoice->remaining(), 1) ? 2 : 0)" required data-digits-decimals-value="2"/>
        @isset($sources['bank'])
            <div class="contents" data-reveal-target="pane" data-reveal-key="bank" @if ($source !== 'bank') hidden @endif>
                <x-ui.field name="paid_at" id="pay-{{ $invoice->id }}-date-bank" label="Дата" type="date" :value="now()->toDateString()"/>
                <x-ui.field name="ref" id="pay-{{ $invoice->id }}-ref-bank" label="№ платёжки"/>
                <x-ui.file-field name="slip" label="Платёжка" accept=".pdf,.jpg,.jpeg,.png,.heic"/>
            </div>
        @endisset
        @isset($sources['cash'])
            <div class="contents" data-reveal-target="pane" data-reveal-key="cash" @if ($source !== 'cash') hidden @endif>
                <x-ui.field name="paid_at" id="pay-{{ $invoice->id }}-date-cash" label="Когда" type="date" :value="now()->toDateString()"/>
            </div>
        @endisset
        @isset($sources['acquiring'])
            <div class="contents" data-reveal-target="pane" data-reveal-key="acquiring" @if ($source !== 'acquiring') hidden @endif>
                <x-ui.field name="paid_at" id="pay-{{ $invoice->id }}-date-acquiring" label="Дата" type="date" :value="now()->toDateString()"/>
                <x-ui.field name="ref" id="pay-{{ $invoice->id }}-ref-acquiring" label="№ операции"/>
            </div>
        @endisset
        <x-ui.field name="note" label="Заметка"/>
    </div>
    <div @class(['sheet-foot' => $sheet])><x-ui.button block>{{ $done }}</x-ui.button></div>
</form>
