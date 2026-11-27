{{-- Карточка организации файлами (27.11.2026, менеджеры и сотрудники CRM: «как с паспортом — просто приложить файлы с реквизитами,
     хоть pdf, хоть docx, хоть фотки, несколько»): строки файлов коллекции `card` контрагента с крестиком и «Приложить карточку
     организации» (выбор нескольких). Грузит и удаляет `card_files_controller` (`PartyCardController`, ответ — этот же блок); пока
     файлы есть, поля ИП и организации не обязательны (снимает `required`, сервер — `PartyRules::passport(files:)`), вносим мы —
     «Проверить реквизиты» → «Заполнить из файлов». --}}
@props(['party', 'key' => 'card'])
@php $files = $party->getMedia('card'); @endphp
<div class="flex flex-col gap-2" data-controller="card-files" data-card-files-url-value="/parties/{{ $party->id }}/card" data-card-files-key-value="{{ $key }}" data-card-files-count-value="{{ $files->count() }}">
    @if ($files->isNotEmpty())
        <div class="list">
            @foreach ($files as $m)
                <x-ui.file class="row" :name="$m->file_name" :href="'/files/'.$m->id" :mime="$m->mime_type" :bytes="$m->size">
                    <button type="button" class="btn btn-ghost btn-s px-1.5 text-ink-muted" aria-label="Удалить файл" data-action="card-files#remove" data-card-files-id-param="{{ $m->id }}"><x-ui.icon name="x" class="size-5"/></button>
                </x-ui.file>
            @endforeach
        </div>
    @endif
    <label class="btn btn-quiet btn-block cursor-pointer">
        <x-ui.icon name="clip" class="size-5"/> {{ $files->isEmpty() ? 'Приложить карточку организации' : 'Приложить ещё' }}
        <input type="file" class="sr-only" multiple accept="image/*,application/pdf,.pdf,.doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" data-action="change->card-files#add">
    </label>
    <p class="px-1 text-sm text-ink-muted" data-card-files-target="hint">{{ $files->isEmpty() ? 'PDF, Word или фото, можно несколько' : 'Реквизиты можно не заполнять: внесём сами' }}</p>
    <p class="field-error hidden" data-card-files-target="error"></p>
</div>
