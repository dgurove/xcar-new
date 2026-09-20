{{-- Счёт из ТС: плательщик пилюлями (вендор / страхователь / покупатель — по отрезкам хранения), хранение — по день
     включительно (отрезки показаны, считаются заново на сервере), невыставленные начисления галками, свободные строки;
     срок и НДС — из вендора. --}}
@php use App\Support\Money; use App\Billing\Accrual; @endphp
<x-ui.shell title="Счёт" :back="[$vehicle->titleWithYear(), '/cars/'.$vehicle->id]">
    @if ($payers->count() > 1)
        <div class="mb-4 flex flex-wrap gap-1.5">
            @foreach ($payers as $p)<x-ui.pill :href="'/cars/'.$vehicle->id.'/invoices/new?payer='.$p" :current="$payer === $p" data-turbo-action="replace">{{ mb_convert_case(Accrual::payerLabel($p), MB_CASE_TITLE, 'UTF-8') }}</x-ui.pill>@endforeach
        </div>
    @endif
    <form method="post" action="/cars/{{ $vehicle->id }}/invoices" id="invoice-form" class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]" data-controller="repeater">
        @csrf
        <x-ui.card title="Кому" class="min-w-0 lg:col-start-2 lg:row-start-1">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field name="party_id" label="Плательщик" :options="$parties" :value="$party?->id" required span="col-span-2"/>
                <x-ui.field name="due_at" label="Оплатить до" type="date" :value="now()->addDays($dueDays)->toDateString()" required/>
                <x-ui.check name="vat" :checked="$vat" class="self-end">С НДС</x-ui.check>
            </div>
        </x-ui.card>
        <div class="flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-start-1">
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
        @if ($segments->isNotEmpty())
            <x-ui.card title="Хранение">
                <div class="flex flex-col gap-2">
                    @foreach ($segments as $s)
                        <div class="row !py-2">
                            <span class="min-w-0 flex-1"><span class="block nums">{{ $s['from']->translatedFormat('j M') }} – {{ $s['to']->translatedFormat('j M') }}</span><span class="row-sub nums">{{ $s['days'] }} сут × {{ Money::rub($s['rate']) }}</span></span>
                            <span class="nums font-semibold">{{ Money::rub($s['amount']) }}</span>
                        </div>
                    @endforeach
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.field name="storage_until" label="По день" type="date" :value="$segments->last()['to']->toDateString()" :min="$segments->first()['from']->toDateString()"/>
                        <x-ui.check name="with_storage" :checked="true" class="self-end">В счёт</x-ui.check>
                    </div>
                </div>
            </x-ui.card>
        @endif
        @if ($pending->isNotEmpty())
            <x-ui.card title="Начислено">
                <div class="flex flex-col gap-2">
                    @foreach ($pending as $c)
                        <label class="row row-check !py-2">
                            <span class="min-w-0 flex-1"><span class="block">{{ $c->title }}</span><span class="row-sub nums">{{ rtrim(rtrim(number_format($c->qty, 2, '.', ''), '0'), '.') }} {{ $c->unitLabel() }} × {{ Money::rub($c->price) }}</span></span>
                            <span class="nums font-semibold">{{ Money::rub($c->amount) }}</span>
                            <span class="check"><input type="checkbox" name="charges[]" value="{{ $c->id }}" checked></span>
                        </label>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
        <x-ui.card title="Ещё строки">
            <div class="flex flex-col gap-2" data-repeater-target="list">
                @for ($n = 0; $n < 2; $n++)
                    <div class="grid grid-cols-[1fr_4rem_6rem] gap-2" data-repeater-target="item">
                        <x-ui.field name="lines[{{ $n }}][title]" placeholder="Название" :id="'line-t-'.$n"/>
                        <x-ui.field name="lines[{{ $n }}][qty]" placeholder="1" :id="'line-q-'.$n" inputmode="decimal"/>
                        <x-ui.field name="lines[{{ $n }}][price]" placeholder="Цена" :id="'line-p-'.$n" inputmode="numeric"/>
                        <input type="hidden" name="lines[{{ $n }}][kind]" value="other">
                    </div>
                @endfor
            </div>
        </x-ui.card>
        <x-ui.field name="notes" label="Заметка в счёт" type="textarea"/>
        </div>
    </form>
    <x-ui.action-bar><x-ui.button form="invoice-form" class="min-w-0 flex-1">Выставить</x-ui.button></x-ui.action-bar>
</x-ui.shell>
