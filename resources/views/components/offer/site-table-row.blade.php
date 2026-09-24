{{-- Строка таблицы предложений на сайте (менеджер, покупатель, избранное). Ячейка в два этажа: название с
     «рекомендуем», под ним номер, приём (таймер, «новый», «закрыт») и город; от 640 номер, приём и город встают
     столбцами. Справа цена так, как видит человек, или «Узнать», под ней на телефоне сколько прошло. Нажатие — окошко. --}}
@props(['offer', 'context' => null])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->role->canBid() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    $href = $context?->offerUrl($offer) ?? "/offers/{$n}";
    $peek = "/offers/{$n}/peek".($context ? '?'.http_build_query($context->query()) : '');
    $fresh = !$gallery && $offer->isFresh();
    $since = $offer->published_at ?? $offer->created_at;
    $city = $offer->settlement?->name;
@endphp
<tr id="offer-{{ $n }}" data-offer-number="{{ $n }}" data-peek-url="{{ $peek }}" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span>
        <span class="cell-sub">
            <span class="sm:hidden">№ {{ $n }}</span>
            @if ($gallery)<span class="sm:hidden text-accent-text">скоро в продаже</span>
            @elseif ($left !== null && $left > 0)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт"></span>
            @elseif ($bids && !$offer->bidsOpen())<span class="sm:hidden">приём закрыт</span>
            @elseif ($fresh)<span class="sm:hidden text-accent-text">новый</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
        </span>
    </td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $n }}</td>
    <td class="hidden sm:table-cell">
        @if ($gallery)<span class="text-accent-text">Скоро в продаже</span>
        @elseif ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>
        @elseif ($bids && !$offer->bidsOpen())<span class="text-ink-dim">Приём закрыт</span>
        @elseif ($fresh)<span class="text-accent-text">Новый</span>@endif
    </td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell">{{ $city }}</td>
    <td class="num nums">
        @if ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted lg:inline">{{ $price::money($price->from) }} → </span>@endif{{ $price::money($price->to) }}@elseif (!$gallery)<span class="text-accent-text">Узнать</span>@endif
        <span class="cell-sub sm:hidden">{!! \App\Support\Ago::time($since) !!}</span>
    </td>
    <td class="cell-dim num col-peek-hide hidden sm:table-cell">{!! \App\Support\Ago::time($since) !!}</td>
</tr>
