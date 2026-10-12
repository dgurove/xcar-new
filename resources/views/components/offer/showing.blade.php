{{-- «Показ» — один модуль у предложения и у сделки (12.10.2026, владелец: «бесит, что „Кому показывать“ в одном месте, а в
     сделке в другом», «сделай их одинаковыми»): первое поле — срок, второе — «Кому показывать» строкой-сводкой со шторкой.
     У предложения срок — «Приём подтверждений до», в шторке волны «кто → когда», ниже галки показа; у сделки — «Сколько
     показывать» (`deal_show_until`), в шторке тот же список без времени (`audience_controller` mode=list). Гараж, архив и
     прочее — модуля нет. Стоит справа под «Ценами» в редакторе (`as=card`) и разделом в окошках (`as=section`); поля
     ходят в форму `form` атрибутом. Только админу. --}}
@props(['offer', 'form', 'as' => 'card', 'summary' => null])
@php
    use App\Offers\{AudienceRules, DealAudience, OfferState};
    $deal = $offer->deal;
    $mode = match (true) {
        ! auth()->user()->canManageCrm() => null,
        $offer->state === OfferState::Sold && $deal && $deal->isActive() && ! $deal->isGarage() => 'deal',
        ! $deal && ! $offer->garageCar && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) => 'offer',
        default => null,
    };
    $n = $offer->number;
    $options = once(fn () => AudienceRules::options());
    if ($mode === 'offer') {
        $rules = AudienceRules::of($offer);
        $who = AudienceRules::summary($rules);
        $hidden = ['name' => 'audience_rules', 'value' => $offer->audience_rules ? json_encode($offer->audience_rules) : ''];
    } elseif ($mode === 'deal') {
        $who = DealAudience::summary($offer);
        $hidden = ['name' => 'deal_audience', 'value' => DealAudience::toJson($offer)];
        // Срок показа: идёт — первым пунктом «До …» (не трогать), кончился — «Показ закончился …»; ниже — выбрать заново.
        $until = $offer->deal_show_until;
        $for = ($until ? ['keep' => ($offer->dealShowOver() ? 'Показ закончился ' : 'До ').$until->translatedFormat('j M, H:i')] : [])
            + ['' => 'Пока идёт сделка'] + DealAudience::FOR;
    }
@endphp
@if ($mode)
<section {{ $attributes->class(['box' => $as === 'card']) }}>
    @if ($as === 'card')
        <div class="mb-3"><h2 class="box-title">Показ</h2></div>
    @else
        <h2 class="detail-section">Показ</h2>
    @endif
    {{-- Блок пришёл в форме: снятые галки и пустой список — снятые (`OfferRequest::payload`). --}}
    <input type="hidden" name="{{ $mode === 'deal' ? '_deal_show' : '_show' }}" value="1" form="{{ $form }}">
    <div class="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-3">
        @if ($mode === 'offer')
            {{-- Срок приёма — выбор даты (`x-ui.date`), время по умолчанию 17:30 (владелец 10.10.2026: «чаще всего надо 17:30»). --}}
            <x-ui.field name="bids_close_at" id="f-bids_close_at-{{ $n }}" label="Приём подтверждений до" type="datetime-local" default-time="17:30" span="col-span-2"
                :value="$offer->bids_close_at?->format('Y-m-d\TH:i')" :form="$form"/>
        @else
            <x-ui.field name="deal_show_for" id="f-deal_show_for-{{ $n }}" label="Сколько показывать" :options="$for" :value="$offer->deal_show_until ? 'keep' : ''" span="col-span-2" :form="$form"/>
        @endif
        <div class="field col-span-2">
            <span class="field-label">Кому показывать</span>
            <div data-controller="audience sheet" data-audience-mode-value="{{ $mode === 'deal' ? 'list' : 'waves' }}" data-audience-options-value="{{ json_encode($options) }}" @if ($mode === 'offer') data-audience-effective-value="{{ json_encode($rules) }}" @endif>
                <input type="hidden" name="{{ $hidden['name'] }}" value="{{ $hidden['value'] }}" form="{{ $form }}" data-audience-target="rules">
                <button type="button" class="field-input flex w-full items-center gap-3 text-left" data-action="sheet#open">
                    <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
                    <span class="min-w-0 flex-1" data-audience-target="summary">{{ $who }}</span>
                    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
                </button>
                <x-ui.sheet id="audience-{{ $n }}-{{ $as }}" title="Кому показывать">
                    <x-audience.editor/>
                </x-ui.sheet>
            </div>
        </div>
        @if ($mode === 'deal')
            <p class="col-span-2 text-sm text-ink-muted">В сделке с {{ $deal->buyer?->shortName() ?? '—' }}</p>
        @endif
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
