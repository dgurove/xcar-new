{{-- Три пункта меню «Опубликовать» (окошко строки, «···» галереи): сейчас, в ближайший и в следующий слот. `button` — пунктами
     шторки (кнопки кита), иначе — строками `.menu`. --}}
@props(['offer', 'button' => false])
@foreach (\App\Offers\Slots::choices() as $c)
    <form method="post" action="/offers/{{ $offer->number }}/state">
        @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ $c['when'] }}">
        @php $label = $c['at'] ? 'В слот '.mb_strtolower(\App\Offers\Slots::label($c['at'])) : 'Опубликовать сейчас'; @endphp
        @if ($button)
            <x-ui.button block :variant="$c['when'] === \App\Offers\Slots::NEAREST ? 'primary' : 'secondary'">{{ $label }}</x-ui.button>
        @else
            <button class="menu-item w-full" role="menuitem" data-action="menu#close">{{ $label }}</button>
        @endif
    </form>
@endforeach
