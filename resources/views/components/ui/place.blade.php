{{-- Место — город, адрес, стоянка, «где сейчас» — всегда с меткой на карте перед
     текстом. Форму задаёт класс снаружи: tag, chip, mark, без класса — в тексте,
     ячейке, <dd>. Пустое — не рисуется. --}}
@if (trim($slot) !== '')<span {{ $attributes->merge(['class' => 'place']) }}><x-ui.icon name="map-pin" class="size-[1em]"/><span class="min-w-0 truncate">{{ $slot }}</span></span>@endif
