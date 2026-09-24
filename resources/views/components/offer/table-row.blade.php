{{-- Строка таблицы предложений (CRM). Ячейка в два этажа: название с «рекомендуем», под ним номер, приём
     (таймер или состояние, цветом по тону) и подтверждения; от 640 они встают своими столбцами. Справа цена
     «до», под ней на телефоне сколько прошло. В галерее вместо подтверждений — интерес. Нажатие — окошко. --}}
@props(['offer', 'gallery' => false])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $left = $gallery ? null : $offer->secondsLeft();
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' };
    $since = $gallery ? $offer->created_at : ($offer->published_at ?? $offer->updated_at);
    $count = $gallery ? (int) $offer->interests_count : (int) $offer->active_bids_count;
    $countWord = $gallery ? 'интерес '.$count : $count.' подтв.';
    $timer = $left !== null && $left > 0;
    $stateWord = $offer->state === OfferState::Open ? 'приём' : mb_strtolower($offer->state->label());
@endphp
<tr id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" data-peek-url="/offers/{{ $n }}/peek{{ $gallery ? '?gallery=1' : '' }}" data-href="/offers/{{ $n }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span>
        <span class="cell-sub">
            <span class="sm:hidden">№ {{ $n }}</span>
            @if ($timer)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт"></span>
            @else<span class="sm:hidden {{ $tone }}">{{ $stateWord }}</span>@endif
            @if ($count)<span class="sm:hidden {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $countWord }}</span>@endif
        </span>
    </td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $n }}</td>
    <td class="hidden sm:table-cell">
        @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>
        @else<span class="{{ $tone }}">{{ $offer->state === OfferState::Open ? 'Приём' : $offer->state->label() }}</span>@endif
    </td>
    <td class="num nums hidden sm:table-cell {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $count ?: '' }}@if (! $gallery && $offer->top_bid)<span class="ml-1 text-sm text-ink-muted">до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif</td>
    <td class="num nums">
        @if ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted lg:inline">{{ $price::money($price->from) }} → </span>@endif{{ $price::money($price->to) }}@elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
        <span class="cell-sub sm:hidden">{!! \App\Support\Ago::time($since) !!}</span>
    </td>
    <td class="cell-dim num col-peek-hide hidden sm:table-cell">{!! \App\Support\Ago::time($since) !!}</td>
</tr>
