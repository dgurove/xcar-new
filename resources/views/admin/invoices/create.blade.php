{{-- Счёт по сделке: кому (менеджер, его покупатель или новый плательщик), за что — сумма по виду одной строкой
     (18.11.2026: за вычетом вознаграждения менеджера, строки «Агентское вознаграждение» нет). --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Счёт по сделке" :back="['№ '.$offer->number, '/offers/'.$offer->number]" narrow>
    @if ($existing->isNotEmpty())
        <div class="mb-4 flex flex-col gap-2">
            @foreach ($existing as $i)
                <a href="{{ \App\Http\Admin\MoneyController::url($i) }}" class="row">
                    <span class="min-w-0 flex-1">{{ $i->label() }} <x-vendor.name :party="$i->party"/></span><x-billing.light :invoice="$i"/><span class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                </a>
            @endforeach
        </div>
    @endif
    <form method="post" action="/work/invoices?offer={{ $offer->number }}" id="invoice-form" class="flex flex-col gap-4"
        data-controller="deal-invoice" data-deal-invoice-bases-value="{{ json_encode($bases) }}">
        @csrf
        <x-ui.card title="Кому">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="party_id" label="Покупатель" :options="$parties" :value="$party->id" required span="col-span-2" data-deal-invoice-target="party" data-action="deal-invoice#party"/>
                <div class="col-span-2 grid grid-cols-2 gap-3" data-deal-invoice-target="newParty" hidden>
                    <x-ui.field name="party_name" label="Название или ФИО" span="col-span-2"/>
                    <x-ui.field name="party_kind" label="Кто" :options="$partyKinds" value="person"/>
                    <x-ui.field name="party_inn" label="ИНН"/>
                    <x-ui.field name="party_phone" label="Телефон" span="col-span-2"/>
                </div>
                <x-ui.field name="due_at" label="Оплатить до" type="date" :value="$due" required/>
            </div>
        </x-ui.card>
        <x-ui.card title="За что">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="kind" label="Вид" :options="$kinds" :value="$kind" data-deal-invoice-target="kind" data-action="deal-invoice#kind"/>
                <x-ui.field name="amount" label="Сумма, ₽" :value="$bases[$kind] ? \App\Support\Money::nums((int) $bases[$kind]) : ''" required data-deal-invoice-target="amount"/>
                <x-ui.field name="title" label="Строка счёта" placeholder="{{ $offer->invoiceTitle() }}" span="col-span-2"/>
            </div>
        </x-ui.card>
        <x-ui.field name="notes" label="Заметка в счёт" type="textarea"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
    </form>
    <x-ui.action-bar><x-ui.button form="invoice-form" class="min-w-0 flex-1">Выставить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
