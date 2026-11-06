{{-- Документ из фото одним PDF (06.11.2026, Сергей: «проще сфоткать документ, чем сканить»; `photo_pdf_controller`): кнопка
     и шторка — «Снять» (камера) и «Из галереи» (несколько снимков сразу), страницы плитками в порядке выбора, название
     (подсказки чипами, номер убытка допишет сервер — `PhotosPdf::fileName`), «Сохранить PDF · N страниц». url — куда
     слать (бумаги ТС — `/cars/{v}/report/pdf` с kind; вложение письма — `/mail/file/pdf`, строка встаёт в append);
     готово — событие `photo-pdf:saved` на обёртке. Поле названия без своей формы: внутри чужой формы оно лишнее и
     сервером не читается. --}}
@props(['id', 'url', 'name' => '', 'chips' => [], 'kind' => null, 'stage' => null, 'vehicle' => null, 'append' => null, 'label' => 'Из фото', 'button' => 'btn btn-s btn-quiet shrink-0 rounded-full'])
<span class="contents" data-controller="sheet photo-pdf" data-photo-pdf-url-value="{{ $url }}" data-photo-pdf-name-value="{{ $name }}"
      @if ($kind) data-photo-pdf-kind-value="{{ $kind }}" @endif @if ($stage) data-photo-pdf-stage-value="{{ $stage }}" @endif @if ($vehicle) data-photo-pdf-vehicle-value="{{ $vehicle }}" @endif @if ($append) data-photo-pdf-append-value="{{ $append }}" @endif {{ $attributes }}>
    <button type="button" class="{{ $button }}" data-action="sheet#open" aria-label="Документ из фото" title="Документ из фото"><x-ui.icon name="camera" class="size-4"/>@if ($label)<span class="hidden sm:inline">{{ $label }}</span>@endif</button>
    <x-ui.sheet :id="$id" title="Документ из фото">
        <div class="photo-pdf-sources">
            <label class="photo-pdf-source"><x-ui.icon name="camera" class="size-6"/><span>Снять</span>
                <input type="file" accept="image/*" capture="environment" class="sr-only" data-action="change->photo-pdf#add">
            </label>
            <label class="photo-pdf-source"><x-ui.icon name="photo" class="size-6"/><span>Из галереи</span>
                <input type="file" accept="image/*,.heic,.heif" multiple class="sr-only" data-action="change->photo-pdf#add">
            </label>
        </div>
        <p class="mt-3 text-center text-sm text-ink-muted" data-photo-pdf-target="empty">Несколько снимков — одним PDF, по странице на снимок</p>
        <div class="report-shots photo-pdf-pages mt-3" data-photo-pdf-target="pages" hidden></div>
        <div class="fields mt-4">
            <x-ui.field name="pdf_name" :id="$id.'-name'" label="Название" :value="$name" placeholder="Как назвать документ" data-photo-pdf-target="name" autocomplete="off"/>
        </div>
        @if ($chips)
            <div class="sheet-chips mt-2">
                @foreach ($chips as $chip)<button type="button" class="chip" data-action="photo-pdf#pick" data-photo-pdf-name-param="{{ $chip }}">{{ $chip }}</button>@endforeach
            </div>
        @endif
        <p class="field-error mt-2 px-1" data-photo-pdf-target="error" hidden></p>
        <div class="photo-pdf-bar mt-3" data-photo-pdf-target="bar" hidden><span></span></div>
        <div class="sheet-foot">
            <button type="button" class="btn btn-accent w-full" data-photo-pdf-target="save" data-action="photo-pdf#save" disabled>Сохранить PDF</button>
        </div>
    </x-ui.sheet>
</span>
