{{-- Строка таблицы предложений (CRM): номер, машина, состояние точкой, подтверждения
     (или интерес в галерее), цена, сколько прошло. Нажатие — окошко (peek). --}}
@props(['offer', 'gallery' => false])
@php
    $n = $offer->number;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $left = $gallery ? null : $offer->secondsLeft();
    $tone = $offer->state->tone();
    $since = $gallery ? $offer->created_at : ($offer->published_at ?? $offer->updated_at);
@endphp
<tr id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" data-peek-url="/offers/{{ $n }}/peek" data-href="/offers/{{ $n }}" tabindex="0">
    <td class="nums text-ink-dim">{{ $n }}</td>
    <td>{{ $offer->titleWithYear() }}</td>
    <td>
        <span class="dot {{ match ($tone) { 'open' => 'dot-open', 'urgent' => 'dot-urgent', 'danger' => 'dot-danger', default => '' } }}"></span>@if ($left !== null && $left > 0)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>@else{{ $offer->state === \App\Offers\OfferState::Open ? 'Приём' : $offer->state->label() }}@endif
    </td>
    @if ($gallery)
        <td class="num nums text-accent-text">{{ $offer->interests_count ?: '' }}</td>
    @else
        <td class="num nums text-urgent">{{ $offer->active_bids_count ?: '' }}@if ($offer->top_bid)<span class="hidden text-ink-dim sm:inline"> до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif</td>
    @endif
    <td class="num nums hidden sm:table-cell">
        @if ($price->shown())@if ($price->withFrom())<span class="text-ink-muted">{{ $price::money($price->from) }} →</span> @endif<span class="font-bold">{{ $price::money($price->to) }}</span>@elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
    </td>
    <td class="num text-ink-dim">{!! \App\Support\Ago::time($since) !!}</td>
</tr>
