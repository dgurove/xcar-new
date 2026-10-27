@php $papers = $vehicle->papers(); @endphp
{{-- Пусто — скрыт: подпись «Документы» с «+ Документ» встаёт без отступа снизу (`.box-head`), ответ загрузки подменит его. --}}
<div id="papers" class="flex flex-col" @if ($papers->isEmpty()) hidden @endif>
    @foreach ($papers as $media)
        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Удалить — управляющему (`park.manage`); приёмщик документы только добавляет. --}}
            @if (auth()->user()->canManagePark())<form method="post" action="/cars/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>@endif
        </x-ui.file>
    @endforeach
</div>
