{{-- Счёт по сделке: кому (менеджер, его покупатель, вендор или новый плательщик), за что — база по виду,
     строки собираются сами: «Транспортное средство» и «Агентское вознаграждение», итого равен базе. --}}
@php use App\Support\Money; use App\Billing\ChargeKind; $fee = (int) $deal->commission; @endphp
<x-ui.shell title="Счёт по сделке" :back="['№ '.$offer->number, '/offers/'.$offer->number]" narrow>
    @if ($existing->isNotEmpty())
        <div class="mb-4 flex flex-col gap-2">
            @foreach ($existing as $i)
                <a href="/work/money/invoices/{{ $i->id }}" class="row">
                    <span class="min-w-0 flex-1">{{ $i->label() }} {{ $i->party->name }}</span><x-billing.light :invoice="$i"/><span class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                </a>
            @endforeach
        </div>
    @endif
    <form method="post" action="/work/invoices?offer={{ $offer->number }}" id="invoice-form" class="flex flex-col gap-4"
        data-controller="deal-invoice" data-deal-invoice-bases-value="{{ json_encode($bases) }}" data-deal-invoice-fee-value="{{ $fee }}"
        data-deal-invoice-withheld-value="{{ $deal->withholds() ? 'true' : 'false' }}" data-deal-invoice-vendor-party-value="{{ $vendorParty?->id ?? 0 }}">
        @csrf
        <x-ui.card title="Кому">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="party_id" label="Плательщик" :options="$parties" :value="$party->id" required span="col-span-2" data-deal-invoice-target="party" data-action="deal-invoice#party"/>
                <div class="col-span-2 grid grid-cols-2 gap-3" data-deal-invoice-target="newParty" hidden>
                    <x-ui.field name="party_name" label="Название или ФИО" span="col-span-2"/>
                    <x-ui.field name="party_kind" label="Кто" :options="$partyKinds" value="person"/>
                    <x-ui.field name="party_inn" label="ИНН"/>
                    <x-ui.field name="party_phone" label="Телефон" span="col-span-2"/>
                </div>
                <x-ui.field name="due_at" label="Оплатить до" type="date" :value="$due" required/>
                <x-ui.check name="vat" :checked="$offer->prices_include_vat" class="self-end">С НДС</x-ui.check>
            </div>
        </x-ui.card>
        <x-ui.card title="За что">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="kind" label="Вид" :options="$kinds" value="sale" data-deal-invoice-target="kind" data-action="deal-invoice#kind"/>
                <x-ui.field name="amount" label="Сумма, ₽" :value="$bases['sale']" required data-deal-invoice-target="amount" data-action="input->deal-invoice#lines"/>
                <x-ui.field name="title" label="Первая строка счёта" placeholder="Транспортное средство {{ $offer->titleWithYear() }}" span="col-span-2"/>
            </div>
            {{-- Как это будет в счёте: строки и итог; удержание — сразу видно, сколько к оплате. --}}
            <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 border-t border-line/40 pt-3">
                <dt class="text-sm" data-deal-invoice-target="first">Транспортное средство</dt><dd class="nums text-right" data-deal-invoice-target="firstSum"></dd>
                <div class="contents" data-deal-invoice-target="fee"><dt class="text-sm">Агентское вознаграждение</dt><dd class="nums text-right">{{ Money::rub($fee) }}<span hidden data-deal-invoice-target="feeSum"></span></dd></div>
                <dt class="font-medium">Итого</dt><dd class="nums text-right font-semibold" data-deal-invoice-target="total"></dd>
                <div class="contents" data-deal-invoice-target="withheld" hidden><dt class="text-sm text-ink-muted">Удерживает сам, к оплате</dt><dd class="nums text-right text-ink-muted">{{ Money::rub($bases['sale'] - $fee) }}</dd></div>
            </dl>
        </x-ui.card>
        <x-ui.field name="notes" label="Заметка в счёт" type="textarea"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
    </form>
    <x-ui.action-bar><x-ui.button form="invoice-form" class="min-w-0 flex-1">Выставить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
