{{-- `papersStage` — карточка «Фото, файл» окна приёма и выдачи: только файлы, добавленные на этом шаге. --}}
@php $papers = ($papersStage ?? null) ? $vehicle->papers()->filter(fn ($m) => $m->getCustomProperty('stage') === $papersStage->value)->values() : $vehicle->papers(); @endphp
{{-- Пусто — скрыт: подпись «Документы» с «+ Документ» встаёт без отступа снизу (`.box-head`), ответ загрузки подменит его. --}}
{{-- Файлы шага окна — свой id (`papers-release`): дело ТС под окном держит свой `#papers`, и поток ответа заменял его, а не
     список окна (06.11.2026, на телефоне документ из фото «не появлялся»). --}}
<div id="{{ ($papersStage ?? null) ? 'papers-'.$papersStage->value : 'papers' }}" class="papers-list flex flex-col" @if ($papers->isEmpty()) hidden @endif>
    @foreach ($papers as $media)
        <x-ui.file :name="$media->getCustomProperty('title') ?: $media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Удалить — управляющему (`park.manage`); приёмщик документы только добавляет. --}}
            @if (auth()->user()->canManagePark())<form method="post" action="/cars/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>@endif
        </x-ui.file>
    @endforeach
</div>
