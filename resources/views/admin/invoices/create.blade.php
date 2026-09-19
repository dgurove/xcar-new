{{-- Счёт по сделке: кому (покупатель — контрагент из профиля, реквизиты дозаполняются на стоянке), за что, сумма, срок. --}}
@php use App\Support\Money; @endphp
<x-ui.shell title="Счёт по сделке" :back="['№ '.$offer->number, '/offers/'.$offer->number]" narrow>
    @if ($existing->isNotEmpty())
        <div class="mb-4 flex flex-col gap-2">
            @foreach ($existing as $i)
                <a href="{{ \App\Support\Surface::Park->url('/money/invoices/'.$i->id) }}" class="row" data-turbo="false">
                    <span class="min-w-0 flex-1">{{ $i->label() }} {{ $i->party->name }}</span><x-billing.light :invoice="$i"/><span class="nums font-semibold">{{ Money::rub($i->remaining() > 0 ? $i->remaining() : $i->total) }}</span>
                </a>
            @endforeach
        </div>
    @endif
    <form method="post" action="/work/invoices?offer={{ $offer->number }}" id="invoice-form" class="flex flex-col gap-4">
        @csrf
        <x-ui.card title="Кому">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="party_id" label="Плательщик" :options="$parties" :value="$party->id" required span="col-span-2"/>
                <x-ui.field name="due_at" label="Оплатить до" type="date" :value="$due" required/>
                <x-ui.check name="vat" :checked="$offer->prices_include_vat" class="self-end">С НДС</x-ui.check>
            </div>
        </x-ui.card>
        <x-ui.card title="За что">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="kind" label="Вид" :options="$kinds" value="sale" span="col-span-2"/>
                <x-ui.field name="title" label="Строка счёта" :value="'Автомобиль '.$offer->titleWithYear().($offer->vin ? ', VIN '.$offer->vin : '')" required span="col-span-2"/>
                <x-ui.field name="amount" label="Сумма, ₽" :value="$deal->amount" inputmode="numeric" required/>
            </div>
        </x-ui.card>
        <x-ui.field name="notes" label="Заметка в счёт" type="textarea"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
    </form>
    <x-ui.action-bar><x-ui.button form="invoice-form" class="min-w-0 flex-1">Выставить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
