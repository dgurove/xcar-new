{{-- Интерес покупателя строкой. Менеджеру важен человек: аватар, имя, ниже машина и цена;
     на странице покупателя (person=false) — с машины. Смахивание — «Связались» / «Закрыть»,
     на десктопе та же кнопка в хвосте. Нажатие — страница предложения; car=false — без строки
     о машине (на её собственной странице). --}}
@php
    $offer = $interest->offer; $person ??= true; $car ??= true; $new = $interest->state === \App\Offers\InterestState::New;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $verb = $new ? ['contacted', 'Связались', 'check', 'swipe-btn-accent'] : ['closed', 'Закрыть', 'x', ''];
@endphp
<x-ui.swipe id="interest-{{ $interest->id }}">
    <div class="row relative transition-colors hover:bg-hover">
        <a href="/offers/{{ $offer->number }}" class="absolute inset-0 rounded-(--radius-l)" aria-label="{{ $offer->titleWithYear() }}"></a>
        @if ($person)
            <x-ui.avatar :user="$interest->user" :size="44"/>
        @else
            <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
        @endif
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span class="truncate font-medium">{{ $person ? $interest->user->name : $offer->titleWithYear() }}</span>
                @unless ($new)<x-ui.pill tone="closed" class="!min-h-0 !py-0.5 text-xs">{{ $interest->state->label() }}</x-ui.pill>@endunless
            </span>
            @if ($car || ($person && $interest->user->phone))
                <span class="row-sub">
                    @if ($person && $car)<span class="truncate">{{ $offer->titleWithYear() }}</span>@endif
                    @if ($car && $price->shown())<span class="nums font-semibold">{{ $price::money($price->to) }}&nbsp;₽</span>@endif
                    @if ($person && $interest->user->phone)<span class="tag nums">{{ $interest->user->phoneFormatted() }}</span>@endif
                </span>
            @endif
            @if ($interest->comment)<span class="mt-1.5 block text-sm">{{ $interest->comment }}</span>@endif
        </span>
        <span class="flex shrink-0 flex-col items-end gap-2">
            <span class="nums text-sm text-ink-dim">{{ $interest->created_at->translatedFormat($interest->created_at->isToday() ? 'H:i' : 'j M') }}</span>
            <button type="submit" form="interest-{{ $interest->id }}-form" class="btn btn-s {{ $new ? 'btn-accent' : 'btn-quiet' }} relative z-10 hidden md:inline-flex">{{ $verb[1] }}</button>
        </span>
    </div>
    <form id="interest-{{ $interest->id }}-form" method="post" action="/account/interest/{{ $interest->id }}" hidden data-queue>@csrf<input type="hidden" name="state" value="{{ $verb[0] }}"><input type="hidden" name="row" value="1"></form>
    <x-slot:actions>
        <button type="submit" form="interest-{{ $interest->id }}-form" class="swipe-btn {{ $verb[3] }}" aria-label="{{ $verb[1] }}"><x-ui.icon :name="$verb[2]" class="size-5"/></button>
    </x-slot:actions>
</x-ui.swipe>
