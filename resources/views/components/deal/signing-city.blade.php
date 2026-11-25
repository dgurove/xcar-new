{{-- Город подписания договора у поставщика (25.11.2026, владелец: у Альфы по записи только в Москве, в остальных городах приходят в
     рабочее время без записи; «на этапе, когда прикладывают документ об оплате, обязательное указание города, без него чек не
     отправляется»; вариант А макетов): «1 Где будет подписание договора?» чипами — Москва, город, где стоит ТС, уже выбранный, «Другой
     город…» (шторка с поиском по справочнику) — выбор сразу отправляется (`SetSigningCity`). Выбран — галка и строка, что это значит.
     `step` — номер в паре «1 город / 2 документ об оплате» блока оплаты; без него — строкой шага подписания. `action` — куда слать,
     `search` — поиск городов (сайт — свой адрес сделки, CRM — справочник). --}}
@props(['deal', 'action', 'search', 'step' => null])
@php
    $moscow = \App\Park\City::main();
    $chosen = $deal->signingCity;
    $here = $deal->offer?->settlement;
    $chips = collect([
        $moscow ? ['id' => $moscow, 'name' => 'Москва', 'note' => null] : null,
        $here && $here->id !== $moscow ? ['id' => $here->id, 'name' => $here->name, 'note' => 'где ТС'] : null,
        $chosen && $chosen->id !== $moscow && $chosen->id !== $here?->id ? ['id' => $chosen->id, 'name' => $chosen->name, 'note' => null] : null,
    ])->filter()->values();
    $sheet = 'signing-city-'.$deal->id.($step ? '-s' : '');
@endphp
<div {{ $attributes->class(['signing-city']) }}>
    <p class="signing-city-q">
        @if ($step)<span @class(['signing-city-num', 'is-done' => $chosen])>@if ($chosen)<x-ui.icon name="check" class="size-3.5"/>@else{{ $step }}@endif</span>@endif
        <span>Где будет подписание договора?@unless ($chosen)<x-ui.req/>@endunless</span>
    </p>
    <div class="signing-city-chips">
        @foreach ($chips as $c)
            <form method="post" action="{{ $action }}" class="contents" data-turbo-frame="_top">
                @csrf<input type="hidden" name="settlement_id" value="{{ $c['id'] }}"><input type="hidden" name="back" value="{{ request()->getRequestUri() }}#money">
                <button type="submit" @class(['signing-city-chip', 'is-on' => $chosen?->id === $c['id']])>{{ $c['name'] }}@if ($c['note'])<small>{{ $c['note'] }}</small>@endif</button>
            </form>
        @endforeach
        <div class="contents" data-controller="sheet">
            <button type="button" class="signing-city-chip" data-action="sheet#open">Другой город…</button>
            <x-ui.sheet :id="$sheet" title="Город подписания">
                <form method="post" action="{{ $action }}" class="flex flex-col gap-4" data-turbo-frame="_top">
                    @csrf<input type="hidden" name="back" value="{{ request()->getRequestUri() }}#money">
                    <div class="fields"><x-ui.combobox name="settlement_id" label="Город" :url="$search" :id="$sheet.'-q'" placeholder="Начните писать название" required :old="false"/></div>
                    <x-ui.sheet-foot type="submit">Выбрать</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    </div>
    @if ($chosen)
        <p class="signing-city-means">
            <x-ui.icon :name="$deal->signsByAppointment() ? 'calendar' : 'clock'" class="size-4 shrink-0"/>
            <span>{{ $deal->signsByAppointment() ? 'По записи, время спросим, когда договор будет готов' : 'Без записи, в рабочее время офиса, адрес пришлём с готовым договором' }}</span>
        </p>
    @endif
    @error('settlement_id')<p class="field-error">{{ $message }}</p>@enderror
</div>
