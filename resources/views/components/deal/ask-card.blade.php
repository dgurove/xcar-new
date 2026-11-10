{{-- Дело менеджера плиткой полки «Ждут вас» на «Предложениях» (10.11.2026, владелец: менеджеры подтверждают предложения,
     а до сделок не доходят): кадр, название, цена, что сделать, срок — просрочено красным; ниже кнопка ответа
     (`x-deal.ask-action`). Нажатие мимо кнопки — сделка (гаражная — машина гаража). --}}
@props(['ask'])
@php
    $deal = $ask->deal;
    $offer = $deal->offer;
@endphp
<article class="ask-card">
    <a href="{{ $deal->href() }}" class="ask-card-head">
        <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
        <span class="min-w-0">
            <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
            <span class="nums block text-sm text-ink-muted">{{ \App\Support\Money::rub($deal->isGarage() ? (int) $deal->cost : (int) $deal->amount) }}</span>
        </span>
    </a>
    <a href="{{ $deal->href() }}" class="ask-card-task">
        <span class="block">{{ \App\Users\Wording::mine($ask->title) }}</span>
        {{-- Срок — как в строке «Ваш ход»: просрочено на сколько, иначе до когда. --}}
        @if ($ask->late())<span class="block text-sm text-danger">просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $ask->due->toIso8601String() }}" data-timer-coarse-value="true"></span></span>
        @elseif ($ask->due)<span class="block text-sm text-ink-muted">до <span class="nums whitespace-nowrap">{!! \App\Support\Ago::html($ask->due, true, words: false) !!}</span></span>
        @else<span class="block text-sm text-ink-muted">без срока</span>@endif
    </a>
    <x-deal.ask-action :ask="$ask" variant="card"/>
</article>
