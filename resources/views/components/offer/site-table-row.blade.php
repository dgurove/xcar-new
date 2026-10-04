{{-- Строка таблицы предложений на сайте (менеджер, покупатель, избранное). Ячейка в два этажа: название с
     «рекомендуем», под ним номер, приём (срок голым числом: со словом «осталось» не влезает город, «новый», «закрыт»)
     и город; от 640 номер, приём и город встают
     столбцами. Справа цена так, как видит человек, или «Узнать», под ней заявленная, иначе на телефоне сколько прошло. Нажатие — карточка. --}}
@props(['offer', 'context' => null])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->role->canBid() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    $fresh = !$gallery && $offer->isFresh();
    $since = $offer->published_at ?? $offer->created_at;
    $city = $offer->settlement?->title();
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="offer-{{ $n }}" data-offer-number="{{ $n }}">
    <td class="grow">
        <x-ui.row-link :key="$n"><span class="cell-title">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span></x-ui.row-link>
        <span class="cell-sub">
            @if ($ref = $offer->leaseRef())<span>ДЛ {{ $ref }}</span>@else<span class="sm:hidden">№ {{ $n }}</span>@endif
            @if ($gallery)<span class="sm:hidden text-accent-text">скоро в продаже</span>
            @elseif ($left !== null && $left > 0)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($bids && !$offer->bidsOpen())<span class="sm:hidden">приём закрыт</span>
            @elseif ($fresh)<span class="sm:hidden text-accent-text">новый</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
            @if ($price->shown() && $price->withFrom())<span class="sm:hidden">{!! \App\Support\Ago::time($since) !!}</span>@endif
        </span>
    </td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $n }}</td>
    <td class="hidden sm:table-cell">
        @if ($gallery)<span class="text-accent-text">Скоро в продаже</span>
        @elseif ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span>
        @elseif ($bids && !$offer->bidsOpen())<span class="text-ink-dim">Приём закрыт</span>
        @elseif ($fresh)<span class="text-accent-text">Новый</span>@endif
    </td>
    <td class="cell-dim col-detail-hide hidden lg:table-cell">{{ $city }}</td>
    <td class="num nums">
        @if ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted lg:inline">{{ $price::money($price->from) }} → </span>@endif{{ $price::money($price->to) }}@elseif (!$gallery)<span class="text-accent-text">Узнать</span>@endif
        {{-- Заявленная (менеджеру — закупочная для него) под ценой, пока стрелка «от → до» не влезает; время тогда уходит под название. --}}
        @if ($price->shown() && $price->withFrom())<span class="cell-sub lg:hidden">{{ $price::money($price->from) }}</span>
        @else<span class="cell-sub sm:hidden">{!! \App\Support\Ago::time($since) !!}</span>@endif
    </td>
    <td class="cell-dim num col-detail-hide hidden sm:table-cell">{!! \App\Support\Ago::time($since) !!}</td>
</tr>
