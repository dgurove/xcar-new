{{-- VIN на экране: нажатие по номеру или по иконке справа кладёт его в буфер
     (copy_controller, тост «VIN в буфере»); нажатие не уходит наверх — карточка
     и окошко строки не откроются. Скрытый VIN (звёздочки, copy=false) — просто
     текст без копирования. Форму задаёт класс снаружи (tag, chip, без класса). --}}
@props(['vin', 'copy' => true])
@if ($vin)
    @if ($copy && !str_contains($vin, '*'))
        <span {{ $attributes->merge(['class' => 'vincode nums']) }} role="button" tabindex="0" title="Скопировать VIN" data-controller="copy" data-copy-text-value="{{ $vin }}" data-copy-done-value="VIN в буфере" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent">{{ $vin }}<x-ui.icon name="copy" class="size-[1em]"/></span>
    @else
        <span {{ $attributes->merge(['class' => 'nums']) }}>{{ $vin }}</span>
    @endif
@endif
