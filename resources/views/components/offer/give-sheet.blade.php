{{-- Лист «Отдать» (18.10.2026, владелец: «договорились с Романом — отдать ему без публикации»; `Give`): кому, куда — «В
     сделку» ценой подтверждения (и без цены продажи, нужна закупочная) или «В гараж», деньги и вывоз. Без подтверждения — открывается
     событием give-{номер}:open (пункт «Действий» карточки и «···» редактора), шторка стоит вне меню: поповер прячет
     вложенное. С подтверждением — «Принять» / «Отдать» у его строки (кнопка — слот): куда, кому и цена — из него. --}}
@props(['offer', 'bid' => null])
@php
    $n = $offer->number;
    $id = $bid ? 'give-bid-'.$bid->id : 'give-'.$n;
    $handOver = (bool) $offer->deal;
    // «В сделку» — и без цены продажи (12.11.2026, начальник): цену подтверждения вписывают тут же; без закупочной — только гараж.
    $deal = (bool) $offer->floor_price;
    // У сделки покупателя — два пути (07.11.2026, начальник): передать полностью (прежняя отменится) или поручить
    // параллельно (прежняя остаётся, никто не знает о другом, `Parallels`).
    $twoWays = $handOver && $bid && ! $bid->isGarage() && ! $offer->deal->isGarage() && ! $offer->deal->isAuction();
    $title = match (true) {
        $twoWays => 'Отдать',
        $handOver => 'Отдать другому',
        $bid?->isGarage() => 'Отдать в гараж',
        (bool) $bid => 'Принять подтверждение',
        default => 'Отдать',
    };
@endphp
<div class="contents" data-controller="sheet" @unless ($bid) data-action="give-{{ $n }}:open@window->sheet#open" @endunless>
    {{ $slot }}
    <x-ui.sheet :id="$id" :title="$title" tall>
        <div class="mb-4 flex flex-wrap items-center gap-1.5">@if ($bid)<x-ui.person :user="$bid->user" full/>@endif<span class="tag">{{ $offer->titleWithYear() }}</span>
            {{-- Срок обязывающего горит (19.10.2026): решать и платить поставщику — до него. --}}
            <x-offer.binding :offer="$offer" aside :pop="false"/>
        </div>
        @if ($twoWays)
            <div class="flex flex-col gap-4" data-controller="reveal">
                <x-ui.segment name="_how" :options="['full' => 'Полностью', 'parallel' => 'Параллельно']" :value="old('parallel') ? 'parallel' : 'full'" pick/>
                <div data-reveal-target="pane" data-reveal-key="full" @if (old('parallel')) hidden @endif>
                    <x-offer.give-deal :offer="$offer" :bid="$bid" :prefix="$id"/>
                </div>
                <div data-reveal-target="pane" data-reveal-key="parallel" @unless (old('parallel')) hidden @endunless>
                    <x-offer.give-deal :offer="$offer" :bid="$bid" :prefix="$id.'-par'" parallel/>
                </div>
            </div>
        @elseif ($bid)
            @if ($bid->isGarage())
                <x-offer.give-garage :offer="$offer" :bid="$bid" :prefix="$id"/>
            @else
                <x-offer.give-deal :offer="$offer" :bid="$bid" :prefix="$id"/>
            @endif
        @else
            <div class="flex flex-col gap-4" @if ($deal) data-controller="reveal" @endif>
                @if ($deal)
                    <x-ui.segment name="_to" :options="['deal' => 'В сделку', 'garage' => 'В гараж']" :value="old('to', 'deal')" pick/>
                    <div data-reveal-target="pane" data-reveal-key="deal" @if (old('to') === 'garage') hidden @endif>
                        <x-offer.give-deal :offer="$offer" :prefix="$id"/>
                    </div>
                @endif
                <div data-reveal-target="pane" data-reveal-key="garage" @if ($deal && old('to') !== 'garage') hidden @endif>
                    <x-offer.give-garage :offer="$offer" :prefix="$id"/>
                </div>
            </div>
        @endif
    </x-ui.sheet>
</div>
