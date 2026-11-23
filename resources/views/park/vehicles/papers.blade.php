{{-- `papersStage` — карточка «Фото, файл» окна приёма и выдачи: только файлы, добавленные на этом шаге. --}}
@php $papers = ($papersStage ?? null) ? $vehicle->papers()->filter(fn ($m) => $m->getCustomProperty('stage') === $papersStage->value)->values() : $vehicle->papers(); @endphp
{{-- Пусто — скрыт: подпись «Документы» с «+ Документ» встаёт без отступа снизу (`.box-head`), ответ загрузки подменит его. --}}
{{-- Файлы шага окна — свой id (`papers-release`): дело ТС под окном держит свой `#papers`, и поток ответа заменял его, а не
     список окна (06.11.2026, на телефоне документ из фото «не появлялся»). --}}
<div id="{{ ($papersStage ?? null) ? 'papers-'.$papersStage->value : 'papers' }}" class="papers-list flex flex-col" @if ($papers->isEmpty()) hidden @endif>
    @foreach ($papers as $media)
        @php $docName = \App\Media\DocName::of($media); @endphp
        @php $base = '/cars/'.$vehicle->id.'/media/'.$media->id; @endphp
        {{-- Как в CRM (23.11.2026, владелец: «проверь в park — документы везде нормально выглядят?»): строка открывает документ,
             «⋯» без плашки — меню: «Распознать», «Переименовать» (всем людям парковки, 13.11.2026, Сергей), «Удалить» (управляющему,
             `park.manage`). Пункты — ссылками с `data-turbo-method`, не формами (16.11.2026, выдача без QR отвечала 405): в окне
             приёма и выдачи строка лежит внутри формы шага, а вложенную форму браузер выбрасывает. --}}
        <x-ui.file :name="$docName" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            <span class="contents" data-controller="file-rename" data-file-rename-url-value="{{ $base }}" data-file-rename-name-value="{{ $docName }}">
                <x-ui.more :id="'park-paper-'.(($papersStage ?? null)?->value ?? 'case').'-'.$media->id" label="Действия с документом" class="more-pill--bare" data-file-rename-target="edit">
                    @if (\App\Mail\Extraction\DocumentText::scannable(new \App\Mail\Scan\Paper($media)))
                        <a href="{{ $base }}/scan" class="menu-item" role="menuitem" data-turbo-method="post" data-turbo-prefetch="false"><x-ui.icon name="sparkles" class="size-5 text-ink-muted"/>Распознать</a>
                    @endif
                    <button type="button" class="menu-item" role="menuitem" data-action="file-rename#start"><x-ui.icon name="edit" class="size-5 text-ink-muted"/>Переименовать</button>
                    @if (auth()->user()->canManagePark())
                        <a href="{{ $base }}" class="menu-item text-danger" role="menuitem" data-turbo-method="delete" data-turbo-confirm="Удалить документ?" data-turbo-prefetch="false"><x-ui.icon name="trash" class="size-5"/>Удалить</a>
                    @endif
                </x-ui.more>
                <button type="button" class="btn btn-ghost btn-s px-2 text-accent-text" data-file-rename-target="save" data-action="file-rename#save" aria-label="Сохранить имя" hidden><x-ui.icon name="check" class="size-5"/></button>
            </span>
        </x-ui.file>
    @endforeach
</div>
