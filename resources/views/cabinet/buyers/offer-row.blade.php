{{-- Машина строкой внутри кабинета менеджера: фото, название, через какую группу открыта, цена.
     Прямой показ снимается смахиванием (телефон) или крестиком в хвосте (десктоп);
     групповой — только на странице группы. hide: ['user' => id] или ['group' => id]. --}}
@php $price = \App\Offers\PriceView::for($offer, auth()->user()); $via ??= collect(); $hide ??= null; @endphp
<x-ui.swipe id="seen-{{ $offer->id }}">
    <div class="row relative transition-colors hover:bg-hover">
        <a href="/offers/{{ $offer->number }}" class="absolute inset-0 rounded-(--radius-l)" aria-label="{{ $offer->titleWithYear() }}"></a>
        <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
        <span class="min-w-0 flex-1">
            <span class="block truncate">{{ $offer->titleWithYear() }}</span>
            <span class="row-sub">
                <span class="tag nums">№ {{ $offer->number }}</span>
                @foreach ($via as $s)<span class="tag">через {{ $s->group->name }}</span>@endforeach
            </span>
        </span>
        @if ($price->shown())<span class="nums shrink-0 text-sm font-semibold">{{ $price::money($price->to) }}&nbsp;₽</span>@endif
        @if ($hide)
            <button type="submit" form="hide-{{ $offer->id }}" class="btn btn-s btn-quiet btn-round relative z-10 hidden shrink-0 md:inline-flex" aria-label="Закрыть предложение"><x-ui.icon name="x" class="size-4"/></button>
        @endif
    </div>
    @if ($hide)
        <form id="hide-{{ $offer->id }}" method="post" action="/account/showings" hidden data-turbo-confirm="Закрыть {{ $offer->titleWithYear() }}?" data-turbo-confirm-label="Закрыть" data-turbo-confirm-text="{{ $hideText ?? '' }}">
            @csrf @method('delete')
            <input type="hidden" name="offer" value="{{ $offer->id }}">
            @foreach ($hide as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        </form>
    @endif
    <x-slot:actions>
        @if ($hide)<button type="submit" form="hide-{{ $offer->id }}" class="swipe-btn" aria-label="Закрыть предложение"><x-ui.icon name="x" class="size-5"/></button>@endif
    </x-slot:actions>
</x-ui.swipe>
