{{-- Шторка на <dialog>: снизу на телефоне, окно по центру от 640. Открывается
     кнопкой data-action="sheet#open" в том же data-controller="sheet" или
     сервером через open, если есть ошибки формы. wide — 42rem, tall — окно чтения
     (письма): от 640 — 48rem, в высоту по содержимому до края экрана, прокрутка внутри;
     inflow — от 1024 стоит в потоке карточкой. --}}
@props(['id', 'title' => null, 'open' => false, 'wide' => false, 'tall' => false, 'inflow' => false])
<dialog id="{{ $id }}" class="sheet{{ $wide ? ' sheet-wide' : '' }}{{ $tall ? ' sheet-tall' : '' }}{{ $inflow ? ' sheet--inflow' : '' }}" data-sheet-target="dialog" data-action="click->sheet#backdrop" @if ($open) data-sheet-open-value="true" @endif {{ $attributes }}>
    @if ($title || !$inflow)
    <div class="mb-4 flex items-center justify-between gap-4">
        @if ($title)<h2 class="text-lg">{{ $title }}</h2>@endif
        <button type="button" class="sheet-close ml-auto" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
    </div>
    @endif
    {{ $slot }}
</dialog>
