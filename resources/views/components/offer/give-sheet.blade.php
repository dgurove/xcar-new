{{-- Лист «Отдать» (18.10.2026, владелец: «договорились с Романом — отдать ему без публикации»; `Give`): кому, куда — «В
     сделку» ценой (только с ценой продажи) или «В гараж» (и без неё), деньги и вывоз. Без подтверждения — открывается
     событием give-{номер}:open (пункт «Действий» карточки и «···» редактора), шторка стоит вне меню: поповер прячет
     вложенное. С подтверждением — «Принять» / «Отдать» у его строки (кнопка — слот): куда, кому и цена — из него. --}}
@props(['offer', 'bid' => null])
@php
    $n = $offer->number;
    $id = $bid ? 'give-bid-'.$bid->id : 'give-'.$n;
    $handOver = (bool) $offer->deal;
    $deal = (bool) $offer->asking_price;
    $title = match (true) {
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
            {{-- Срок обязывающего (19.10.2026): решать и платить поставщику — до него. --}}
            @if (\App\Offers\Binding::matters($offer))
                @php $bindingWord = \App\Offers\Binding::word($offer); @endphp
                <span @class(['text-sm nums', 'font-medium text-danger' => $bindingWord, 'text-ink-muted' => ! $bindingWord])>обязывающее до {{ \App\Offers\Binding::date($offer->binding_until) }}@if ($bindingWord), {{ $bindingWord }}@endif</span>
            @endif
        </div>
        @if ($bid)
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
