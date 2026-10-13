{{-- Пункты «На аукцион» в «Действиях» (`Offer::auctionActions`, 13.10.2026): menu — меню карточки строки, иначе кнопки
     шторки «···» редактора. «Отменить отправку на аукцион» встаёт вместо «По истечению срока на аукцион». На аукционе —
     «Вернуть в продажу…» (шторка `x-offer.resale-sheet` с ценами и показом, событие resale:open) и «Вернуть в черновики». --}}
@php $menu ??= false; @endphp
@foreach ($offer->auctionActions() as [$mode, $label, $ask])
    @php $icon = match (true) { $mode === 'sale' => 'send', $mode === 'after' => 'clock', $mode === 'cancel' && $offer->onAuction() => 'eye-off', $mode === 'cancel' => 'undo', default => 'flag' }; @endphp
    @if ($mode === 'sale')
        @if ($menu)
            <button type="button" class="menu-item" role="menuitem" data-controller="emit" data-action="emit#send menu#close" data-emit-event-param="resale:open"><x-ui.icon :name="$icon" class="size-5 text-ink-muted"/>{{ $label }}</button>
        @else
            <x-ui.button type="button" block data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="resale:open">{{ $label }}</x-ui.button>
        @endif
        @continue
    @endif
    <form method="post" action="/offers/{{ $offer->number }}/auction" @if ($ask) data-turbo-confirm="{{ $ask }}" @endif>
        @csrf
        @if ($mode === 'cancel')@method('delete')@else<input type="hidden" name="mode" value="{{ $mode }}">@endif
        @if ($menu)
            <button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon :name="$icon" class="size-5 text-ink-muted"/>{{ $label }}</button>
        @else
            <x-ui.button block variant="secondary">{{ $label }}</x-ui.button>
        @endif
    </form>
@endforeach
