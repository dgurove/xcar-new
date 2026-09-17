{{-- Строка таблицы предложений на сайте (менеджер, покупатель, избранное):
     машина, метка приёма (новый, таймер, закрыт), цена как видит человек,
     сколько прошло. Нажатие — окошко (peek). --}}
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
@endphp
<tr id="offer-{{ $n }}" data-offer-number="{{ $n }}" data-peek-url="{{ $peek }}" data-href="{{ $href }}" tabindex="0">
    <td class="nums text-ink-dim">{{ $n }}</td>
    <td class="grow">@if ($fresh)<span class="dot dot-open"></span>@endif{{ $offer->titleWithYear() }}</td>
    <td class="hidden sm:table-cell">
        @if ($gallery)<span class="text-accent-text">Скоро в продаже</span>
        @elseif ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>
        @elseif ($bids && !$offer->bidsOpen())<span class="text-ink-dim">Приём закрыт</span>
        @elseif ($fresh)<span class="text-accent-text">Новый</span>@endif
    </td>
    <td class="hidden text-ink-dim sm:table-cell">{{ $offer->settlement?->name }}</td>
    <td class="num nums">
        @if ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted sm:inline">{{ $price::money($price->from) }} →</span> @endif<span class="font-bold">{{ $price::money($price->to) }}</span>@elseif (!$gallery)<span class="text-accent-text">Узнать</span>@endif
    </td>
    <td class="num text-ink-dim">{!! \App\Support\Ago::time($offer->published_at ?? $offer->created_at) !!}</td>
</tr>
