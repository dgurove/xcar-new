{{-- «Показ» — один модуль у предложения и у сделки (12.10.2026, владелец: «бесит, что „Кому показывать“ в одном месте, а в
     сделке в другом», «сделай их одинаковыми»): первое поле — срок обязывающего (у вендора по комиссии), дальше срок показа и «Кому показывать» строкой-сводкой со шторкой.
     У предложения срок — «Приём подтверждений до», в шторке волны «кто → когда», ниже галки показа; у сделки — «Сколько
     показывать» (`deal_show_until`), в шторке тот же список без времени (`audience_controller` mode=list). Гараж, архив и
     прочее — модуля нет. Стоит справа под «Ценами» в редакторе (`as=card`) и разделом в окошках (`as=section`); у черновика и
     открытого — полями шторки «Показ» за строкой-сводкой (`as=sheet`, `x-offer.showing-row`, 19.10.2026). Поля ходят в форму
     `form` атрибутом. Только админу. --}}
@props(['offer', 'form', 'as' => 'card', 'summary' => null])
@php
    use App\Offers\{DealAudience, OfferState};
    $deal = $offer->deal;
    $mode = match (true) {
        ! auth()->user()->canManageCrm() => null,
        $offer->state === OfferState::Sold && $deal && $deal->isActive() && ! $deal->isGarage() => 'deal',
        ! $deal && ! $offer->garageCar && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) => 'offer',
        default => null,
    };
    $n = $offer->number;
    if ($mode === 'deal') {
        // Срок показа: идёт — первым пунктом «До …» (не трогать), кончился — «Показ закончился …»; ниже — выбрать заново.
        $until = $offer->deal_show_until;
        $for = ($until ? ['keep' => ($offer->dealShowOver() ? 'Показ закончился ' : 'До ').\App\Support\Ago::day($until, true)] : [])
            + ['' => 'Пока идёт сделка'] + DealAudience::FOR;
    }
@endphp
@if ($mode)
<section {{ $attributes->class(['box' => $as === 'card']) }}>
    @if ($as === 'sheet')
    @elseif ($as === 'card')
        <div class="mb-3"><h2 class="box-title">Показ</h2></div>
    @else
        <h2 class="detail-section">Показ</h2>
    @endif
    {{-- Блок пришёл в форме: снятые галки и пустой список — снятые (`OfferRequest::payload`). --}}
    <input type="hidden" name="{{ $mode === 'deal' ? '_deal_show' : '_show' }}" value="1" form="{{ $form }}">
    <div class="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-3">
        {{-- Срок обязывающего (19.10.2026, владелец: «туда хорошо подходит»): данность от страховой, до когда поставщик держит
             закупочную; приём подтверждений — не позже. Только вендор по комиссии (Совкомбанк), сменили вендора — прячется. --}}
        @php $bindingDays = $offer->vendor_id ? (int) \App\Vendors\Vendor::whereKey($offer->vendor_id)->value('binding_days') : 0; @endphp
        <div class="col-span-2" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode(\App\Offers\Binding::vendorIds()) }}" @unless (\App\Offers\Binding::applies($offer)) hidden @endunless>
            <x-ui.field name="binding_until" :id="'f-binding_until-'.$n.'-'.$as" label="Срок обязывающего предложения" type="datetime-local" default-time="23:59"
                :value="$offer->binding_until?->format('Y-m-d\TH:i')" :placeholder="$bindingDays ? 'по вендору '.$bindingDays.' дн' : null" :form="$form"/>
        </div>
        @if ($mode === 'offer')
            {{-- Срок приёма — выбор даты (`x-ui.date`), время по умолчанию 17:30 (владелец 10.10.2026: «чаще всего надо 17:30»). --}}
            <x-ui.field name="bids_close_at" id="f-bids_close_at-{{ $n }}" label="Приём подтверждений до" type="datetime-local" default-time="17:30" span="col-span-2"
                :value="$offer->bids_close_at?->format('Y-m-d\TH:i')" :limit="\App\Offers\Binding::closeLimit($offer)" :form="$form"/>
        @else
            <x-ui.field name="deal_show_for" id="f-deal_show_for-{{ $n }}" label="Сколько показывать" :options="$for" :value="$offer->deal_show_until ? 'keep' : ''" span="col-span-2" :form="$form"/>
        @endif
        <x-offer.audience-field :offer="$offer" :form="$form" :mode="$mode === 'deal' ? 'list' : 'waves'" :key="$as" class="col-span-2"/>
        @if ($mode === 'offer' && ($summary ?? collect())->isNotEmpty())
            <div class="col-span-2 flex flex-col gap-1.5 text-sm">
                @foreach ($summary as $row)
                    <div class="flex items-center gap-2"><x-ui.person :user="$row['manager']"/><span class="text-ink-muted">открыл {{ $row['buyers'] }} {{ \App\Support\Plural::of($row['buyers'], ['покупателю', 'покупателям', 'покупателям']) }}</span></div>
                @endforeach
            </div>
        @endif
        @if ($mode === 'offer')
            <div class="flags col-span-2">
                <x-ui.check name="recommended" :checked="$offer->recommended" :form="$form">Рекомендуем</x-ui.check>
                <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled" :form="$form">Чат с покупателями</x-ui.check>
                <x-ui.check name="share_locked" :checked="$offer->share_locked" :form="$form">Запретить шеринг</x-ui.check>
                <x-ui.check name="garage_allowed" :checked="$offer->garage_allowed" :form="$form">Можно в гараж</x-ui.check>
            </div>
        @endif
    </div>
</section>
@endif
