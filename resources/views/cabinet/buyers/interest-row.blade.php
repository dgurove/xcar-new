{{-- Интерес покупателя строкой. Менеджеру важен человек: аватар, имя, ниже машина и цена;
     на странице покупателя (person=false) — с машины. «Я связался» / «Закрыть» — словом в хвосте и смахиванием. Нажатие — страница предложения; car=false — без строки
     о машине (на её собственной странице). --}}
@php
    $offer = $interest->offer; $person ??= true; $car ??= true; $new = $interest->state === \App\Offers\InterestState::New;
    // Машина ушла от менеджера (сузили круг, сделка, снята) — интерес остаётся строкой, но без цены, фото и ссылки.
    $seen = $offer->isVisibleTo(auth()->user());
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    // Кнопка называет исход, от первого лица (11.10.2026: голое «Связались» — правило no-em-dash-in-ui).
    $verb = $new ? ['contacted', 'Я связался', 'check', 'swipe-btn-accent'] : ['closed', 'Закрыть', 'x', ''];
@endphp
<x-ui.swipe id="interest-{{ $interest->id }}">
    <div class="row relative transition-colors hover:bg-hover">
        @if ($seen)<a href="/offers/{{ $offer->number }}" class="absolute inset-0 rounded-(--radius-l)" aria-label="{{ $offer->titleWithYear() }}"></a>@endif
        @if ($person)
            <x-ui.avatar :user="$interest->user" :size="44"/>
        @else
            <span class="row-photo">@if ($seen)<x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/>@endif</span>
        @endif
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate font-medium">{{ $person ? $interest->user->name : $offer->titleWithYear() }}</span>
                @unless ($new)<x-ui.state tone="closed">{{ $interest->state->label() }}</x-ui.state>@endunless
            </span>
            @if ($car || ($person && $interest->user->phone))
                <span class="row-sub">
                    @if ($person && $car)<span class="truncate">{{ $offer->titleWithYear() }}</span>@endif
                    @if ($car && $seen && $price->shown())<span class="nums font-semibold">{{ $price::money($price->to) }}&nbsp;₽</span>@endif
                    @if ($person && $interest->user->phone)<span class="tag nums">{{ $interest->user->phoneFormatted() }}</span>@endif
                </span>
            @endif
            @if ($interest->comment)<span class="mt-1.5 block text-sm">{{ $interest->comment }}</span>@endif
        </span>
        <span class="flex shrink-0 flex-col items-end gap-2">
            <span class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($interest->created_at, short: true) }}</span>
            {{-- Слово-действие в хвосте — и на телефоне: смахивание одно не находят (как с долгим нажатием, владелец 04.10.2026). --}}
            <button type="submit" form="interest-{{ $interest->id }}-form" class="relative z-10 text-sm font-medium {{ $new ? 'text-accent-text' : 'text-ink-muted' }} hover:underline">{{ $verb[1] }}</button>
        </span>
    </div>
    <form id="interest-{{ $interest->id }}-form" method="post" action="/buyers/interest/{{ $interest->id }}" hidden data-queue>@csrf<input type="hidden" name="state" value="{{ $verb[0] }}"><input type="hidden" name="row" value="1"></form>
    <x-slot:actions>
        <button type="submit" form="interest-{{ $interest->id }}-form" class="swipe-btn {{ $verb[3] }}" aria-label="{{ $verb[1] }}"><x-ui.icon :name="$verb[2]" class="size-5"/></button>
    </x-slot:actions>
</x-ui.swipe>
