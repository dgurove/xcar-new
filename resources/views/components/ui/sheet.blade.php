{{-- Шторка. Открывается кнопкой с data-action="sheet#open" внутри того же
     data-controller="sheet", или сервером через open, если есть ошибки формы. --}}
@props(['id', 'title' => null, 'open' => false])
<dialog id="{{ $id }}" class="sheet" data-sheet-target="dialog" data-action="click->sheet#backdrop" @if ($open) data-sheet-open-value="true" @endif {{ $attributes }}>
    <div class="flex items-start justify-between gap-4">
        @if ($title)<h2 class="text-lg">{{ $title }}</h2>@endif
        <button type="button" class="btn btn-ghost btn-sm -mr-2 -mt-1 ml-auto" data-action="sheet#close" aria-label="Закрыть">
            <x-ui.icon name="x" class="size-5"/>
        </button>
    </div>
    <div class="mt-3">{{ $slot }}</div>
</dialog>
