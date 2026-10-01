{{-- Шторка на <dialog>: снизу на телефоне, окно по центру от 640. Открывается
     кнопкой data-action="sheet#open" в том же data-controller="sheet" или
     сервером через open, если есть ошибки формы. wide — 42rem, tall — окно чтения
     (письма): на телефоне во весь экран с «‹» слева, от 640 — 48rem по центру, в высоту
     по содержимому до края экрана, прокрутка внутри; inflow — от 1024 стоит в потоке карточкой;
     bare — без строки заголовка с крестиком: закрывают свайпом, фоном и своей кнопкой (подключение Telegram);
     tools — кнопки в строке заголовка перед крестиком (окно писем: ✨ своего предмета). --}}
@props(['id', 'title' => null, 'open' => false, 'wide' => false, 'tall' => false, 'inflow' => false, 'bare' => false, 'tools' => null])
<dialog id="{{ $id }}" {{ $attributes->class(['sheet', 'sheet-wide' => $wide, 'sheet-tall' => $tall, 'sheet--inflow' => $inflow]) }} data-sheet-target="dialog" data-action="click->sheet#backdrop" @if ($open) data-sheet-open-value="true" @endif>
    @if (! $bare && ($title || !$inflow))
    <div class="mb-4 flex items-center justify-between gap-3{{ $tall ? ' sheet-head' : '' }}">
        @if ($tall)<button type="button" class="sheet-close sheet-back -ml-2" data-action="sheet#close" aria-label="Назад"><x-ui.icon name="chevron-left" class="size-5"/></button>@endif
        {{-- Высокое окно: заголовок слева у «‹», даже когда крестик на телефоне спрятан и справа ничего нет. --}}
        @if ($title)<h2 @class(['text-lg', 'min-w-0 flex-1 truncate' => $tall])>{{ $title }}</h2>@endif
        @if ($tools)<span class="ml-auto flex items-center gap-2">{{ $tools }}</span>@endif
        <button type="button" class="sheet-close{{ $tools ? '' : ' ml-auto' }}{{ $tall ? ' sheet-x' : '' }}" data-action="sheet#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
    </div>
    @endif
    {{ $slot }}
</dialog>
