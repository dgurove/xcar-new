{{-- «Показ» — поля шторки «Показ» (12.10.2026, владелец: «бесит, что „Кому показывать“ в одном месте, а в сделке в другом»;
     12.11.2026 — «это всё одно и то же»): срок обязывающего (у вендора по комиссии), «Кому показывать» (`x-audience.field`: лента
     волн, в шторке конец приёма — её концом), галки показа. С 23.11.2026 — только внутри шторки строки «Показ»
     (`x-offer.showing-row`, ей распоряжается `x-offer.showing-line` в блоке «Цены» страницы и окна): отдельной карточки и раздела
     больше нет, публикация сделки для других — строкой там же. Поля ходят в форму `form` атрибутом. Только админу. --}}
@props(['offer', 'form', 'as' => 'sheet', 'summary' => null])
@php
    use App\Offers\{AudienceRules, OfferState};
    $mode = match (true) {
        ! auth()->user()->canManageCrm() => null,
        ! $offer->deal && ! $offer->garageCar && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) => 'offer',
        default => null,
    };
    $n = $offer->number;
@endphp
@if ($mode)
<section {{ $attributes }}>
    {{-- Блок пришёл в форме: снятые галки — снятые (`OfferRequest::payload`). --}}
    <input type="hidden" name="_show" value="1" form="{{ $form }}">
    <div class="grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-3">
        {{-- Срок обязывающего (19.10.2026, владелец: «туда хорошо подходит») — общим куском `x-offer.binding-field`. --}}
        <x-offer.binding-field :offer="$offer" :id="'f-binding_until-'.$n.'-'.$as" :form="$form" span="col-span-2"/>
        {{-- Не вписан — посчитает публикация (`Slots::closeFor`), поэтому без умолчания. В шторке — концом ленты волн
             (15.11.2026: последняя волна идёт до него), в карточке — своим полем над строкой «Кому». --}}
        @php
            $base = $offer->state === OfferState::Open ? $offer->published_at : $offer->slot_at;
        @endphp
        {{-- Эксклюзив за оценку идёт (`Exclusive`, 20.11.2026: Андрей ставил «Всем», а оставался только Роман) — виден здесь и
             снимается переключателем со «Вы уверены?»; тогда волны ниже встают как выбрали (`UpdateOffer`, `exclusive_off`). --}}
        @php $running = $offer->state === OfferState::Open ? \App\Offers\Exclusive::running($offer) : collect(); @endphp
        @if ($running->isNotEmpty())
            <div class="col-span-2" data-controller="exclusive" data-exclusive-text-value="{{ \App\Offers\Exclusive::warning($running) }}">
                <input type="hidden" name="exclusive_off" value="0" form="{{ $form }}" data-exclusive-target="off">
                <label class="check">
                    <input type="checkbox" switch checked data-exclusive-target="toggle" data-action="exclusive#change">
                    <span>Только {{ \App\Offers\Exclusive::who($running) }} до {{ \App\Support\Ago::day($running->min('exclusive_until'), true) }}, за оценку</span>
                </label>
            </div>
        @endif
        @if ($as === 'sheet')
            <x-audience.field :value="$offer->audience_rules ? json_encode($offer->audience_rules) : ''" :rules="AudienceRules::of($offer)" :form="$form"
                inline :base="$base" :fresh="$offer->state !== OfferState::Open" :sheet="'audience-'.$n.'-'.$as" class="col-span-2">
                <x-slot:end>
                    <x-offer.close-field :offer="$offer" :id="'f-bids_close_at-'.$n.'-'.$as" :value="$offer->bids_close_at?->format('Y-m-d\TH:i') ?? ''" :required="false" :form="$form" label="Конец приёма"/>
                </x-slot:end>
            </x-audience.field>
        @else
            <x-offer.close-field :offer="$offer" :id="'f-bids_close_at-'.$n.'-'.$as" :value="$offer->bids_close_at?->format('Y-m-d\TH:i') ?? ''" :required="false" :form="$form" span="col-span-2"/>
            <x-audience.field :value="$offer->audience_rules ? json_encode($offer->audience_rules) : ''" :rules="AudienceRules::of($offer)" :form="$form"
                :fresh="$offer->state !== OfferState::Open" :sheet="'audience-'.$n.'-'.$as" class="col-span-2"/>
        @endif
        @if (($summary ?? collect())->isNotEmpty())
            <div class="col-span-2 flex flex-col gap-1.5 text-sm">
                @foreach ($summary as $row)
                    <div class="flex items-center gap-2"><x-ui.person :user="$row['manager']"/><span class="text-ink-muted">открыл {{ $row['buyers'] }} {{ \App\Support\Plural::of($row['buyers'], ['покупателю', 'покупателям', 'покупателям']) }}</span></div>
                @endforeach
            </div>
        @endif
        <div class="flags col-span-2">
            <x-ui.check name="recommended" :checked="$offer->recommended" :form="$form">Рекомендуем</x-ui.check>
            <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled" :form="$form">Чат с покупателями</x-ui.check>
            <x-ui.check name="share_locked" :checked="$offer->share_locked" :form="$form">Запретить шеринг</x-ui.check>
            <x-ui.check name="garage_allowed" :checked="$offer->garage_allowed" :form="$form">Можно в гараж</x-ui.check>
        </div>
    </div>
</section>
@endif
