{{-- Пункты «На аукцион» в «Действиях» (`Offer::auctionActions`, 13.10.2026): menu — меню карточки строки, иначе кнопки
     шторки «···» редактора. «Отменить отправку на аукцион» встаёт вместо «По истечению срока на аукцион». --}}
@php $menu ??= false; @endphp
@foreach ($offer->auctionActions() as [$mode, $label, $ask])
    <form method="post" action="/offers/{{ $offer->number }}/auction" @if ($ask) data-turbo-confirm="{{ $ask }}" @endif>
        @csrf
        @if ($mode === 'cancel')@method('delete')@else<input type="hidden" name="mode" value="{{ $mode }}">@endif
        @if ($menu)
            <button class="menu-item" role="menuitem" data-action="menu#close"><x-ui.icon :name="$mode === 'cancel' ? 'undo' : ($mode === 'after' ? 'clock' : 'flag')" class="size-5 text-ink-muted"/>{{ $label }}</button>
        @else
            <x-ui.button block variant="secondary">{{ $label }}</x-ui.button>
        @endif
    </form>
@endforeach
