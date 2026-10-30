{{-- Подтверждение строкой («Мои подтверждения»): фото, название, №, справа цена; состояние называет заголовок раздела.
     Ждёт решения — когда итоги (11.10.2026): пока приём открыт — «итоги после 13 окт, 17:00», после — «приём закрыт, ждём итогов» (голое «выбираем»
     не говорило, кто и что); в резерве — так же, о чужой сделке ни слова (15.10.2026). Гаражное — словом «В гараж» вместо цены. --}}
@php
    $offer = $bid->offer;
    $when = $offer->bids_close_at?->isFuture() ? 'итоги после '.\App\Support\Ago::day($offer->bids_close_at, true) : 'приём закрыт, ждём итогов';
    $price = $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount);
@endphp
<a href="/offers/{{ $offer->number }}" class="row">
    <span class="row-photo max-sm:!h-[2.625rem] max-sm:!w-14"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub max-sm:!hidden"><span class="tag nums">№ {{ $offer->number }}</span>@if ($when)<span class="text-sm text-ink-muted">{{ $when }}</span>@endif</span>
        {{-- Телефон: цена второй строкой с номером, когда итоги — своей строкой (хвостом за номером резался многоточием). --}}
        <span class="mt-0.5 flex items-baseline gap-x-2 sm:hidden">
            <span class="nums shrink-0 font-medium">{{ $price }}</span>
            <span class="nums min-w-0 truncate text-sm text-ink-muted">№&nbsp;{{ $offer->number }}</span>
        </span>
        @if ($when)<span class="mt-1 block text-sm text-ink-muted sm:hidden">{{ $when }}</span>@endif
    </span>
    <span class="nums shrink-0 font-medium max-sm:!hidden">{{ $price }}</span>
</a>
