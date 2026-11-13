{{-- `papersStage` — карточка «Фото, файл» окна приёма и выдачи: только файлы, добавленные на этом шаге. --}}
@php $papers = ($papersStage ?? null) ? $vehicle->papers()->filter(fn ($m) => $m->getCustomProperty('stage') === $papersStage->value)->values() : $vehicle->papers(); @endphp
{{-- Пусто — скрыт: подпись «Документы» с «+ Документ» встаёт без отступа снизу (`.box-head`), ответ загрузки подменит его. --}}
{{-- Файлы шага окна — свой id (`papers-release`): дело ТС под окном держит свой `#papers`, и поток ответа заменял его, а не
     список окна (06.11.2026, на телефоне документ из фото «не появлялся»). --}}
<div id="{{ ($papersStage ?? null) ? 'papers-'.$papersStage->value : 'papers' }}" class="papers-list flex flex-col" @if ($papers->isEmpty()) hidden @endif>
    @foreach ($papers as $media)
        @php $docName = \App\Media\DocName::of($media); @endphp
        <x-ui.file :name="$docName" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Переименовать — всем людям парковки (13.11.2026, Сергей); удалить — управляющему (`park.manage`). --}}
            <x-ui.file-rename :action="'/cars/'.$vehicle->id.'/media/'.$media->id" :name="$docName"/>
            @if (auth()->user()->canManagePark())<form method="post" action="/cars/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>@endif
        </x-ui.file>
    @endforeach
</div>
