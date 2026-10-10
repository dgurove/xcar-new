{{-- Подтверждение строкой: фото, название, №, справа цена; состояние — только в архиве, в секции «Ждут решения» его
     называет заголовок. Там вместо даты подачи — когда итоги (11.10.2026): пока приём открыт — «итоги после 13 окт, 17:00»,
     после — «выбираем». Гаражное — словом «В гараж» вместо цены. --}}
@php
    $offer = $bid->offer;
    $when = $state
        ? ($bid->decided_at ?? $bid->updated_at)?->translatedFormat('j M')
        : ($offer->bids_close_at?->isFuture() ? 'итоги после '.$offer->bids_close_at->translatedFormat('j M, H:i') : 'выбираем');
    // В архиве — исход словом и дата, как у отменённой сделки рядом: отклонено красным, отозвано серым.
    $outcome = $state ? '<span class="'.($bid->state === \App\Offers\BidState::Declined ? 'text-danger' : 'text-ink-muted').'">'.e($bid->state->label()).'</span> ' : '';
@endphp
<a href="/offers/{{ $offer->number }}" class="row">
    <span class="row-photo max-sm:!h-[2.625rem] max-sm:!w-14"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub max-sm:!hidden"><span class="tag nums">№ {{ $offer->number }}</span>@if ($when && ! $state)<span class="text-sm text-ink-muted">{{ $when }}</span>@endif</span>
        {{-- Телефон: цена второй строкой с номером, текст во всю ширину (как у строки сделки). --}}
        <span class="mt-0.5 flex items-baseline gap-x-2 sm:hidden">
            <span class="nums shrink-0 font-medium">{{ $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount) }}</span>
            <span class="nums min-w-0 truncate text-sm text-ink-muted">№&nbsp;{{ $offer->number }}</span>
        </span>
        {{-- Когда итоги — своей строкой, а не хвостом за номером: на 375 хвост резался многоточием. --}}
        @if ($when)<span class="mt-1 block text-sm sm:hidden">{!! $outcome !!}<span class="nums text-ink-muted">{{ $when }}</span></span>@endif
    </span>
    <span class="flex shrink-0 flex-col items-end gap-1.5 max-sm:!hidden">
        <span class="nums font-medium">{{ $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount) }}</span>
        @if ($state)<span class="text-sm">{!! $outcome !!}<span class="nums text-ink-muted">{{ $when }}</span></span>@endif
    </span>
</a>
