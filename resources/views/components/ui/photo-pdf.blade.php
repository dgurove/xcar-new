{{-- «Приложить» одним документом (06.11.2026 — «Документ из фото» парковки, Сергей: «проще сфоткать документ, чем сканить»;
     24.11.2026 — и сделки, владелец: «как в нормальном приложении»; `photo_pdf_controller`). Кнопка — сама выбор файлов: телефон
     показывает своё меню («Фото-медиатека», «Снять фото», «Выбрать файлы» — слова iOS, сайт их не меняет; своего меню нет —
     иначе два меню подряд). Выбрали — шторка: что выбрали, плитками по порядку («×», «‹» раньше, «+» — тот же выбор),
     название заполнено (подсказки чипами), «Сохранить». Сервер собирает один документ (`PhotosPdf::document`): фото и PDF —
     одним PDF по порядку, один PDF — как есть под новым именем, Word (`word`) — только один и без фото.
     url — куда слать (бумаги ТС — `/cars/{v}/report/pdf` с kind; вложение письма — `/mail/file/pdf`, строка встаёт в append;
     сделка — `…/doc`, ответ `reload`); готово — событие `photo-pdf:saved` на обёртке. Поле названия без своей формы: внутри
     чужой формы оно лишнее и сервером не читается. Слот `trigger` — свой вид кнопки (строка «Продажи»), `fields` — что ещё
     отправить (`pass` у платёжного поручения за менеджера). --}}
@props(['id', 'url', 'name' => '', 'chips' => [], 'kind' => null, 'stage' => null, 'vehicle' => null, 'append' => null, 'label' => 'Из фото',
    'button' => 'btn btn-s btn-quiet shrink-0 rounded-full', 'always' => false, 'title' => 'Документ из фото', 'icon' => 'camera', 'word' => false, 'fields' => []])
@php $accept = 'image/*,.heic,.heif,.pdf'.($word ? ',.doc,.docx' : ''); @endphp
<span class="contents" data-controller="sheet photo-pdf" data-photo-pdf-url-value="{{ $url }}" data-photo-pdf-name-value="{{ $name }}" @if ($word) data-photo-pdf-word-value="true" @endif
      @if ($fields) data-photo-pdf-fields-value="{{ json_encode($fields) }}" @endif
      @if ($kind) data-photo-pdf-kind-value="{{ $kind }}" @endif @if ($stage) data-photo-pdf-stage-value="{{ $stage }}" @endif @if ($vehicle) data-photo-pdf-vehicle-value="{{ $vehicle }}" @endif @if ($append) data-photo-pdf-append-value="{{ $append }}" @endif {{ $attributes }}>
    <label class="{{ $button }} cursor-pointer" aria-label="{{ $label ?: $title }}" title="{{ $label ?: $title }}">
        @if (isset($trigger))
            {{ $trigger }}
        @else
            <x-ui.icon :name="$icon" class="size-4"/>@if ($label)<span @class(['hidden sm:inline' => ! $always])>{{ $label }}</span>@endif
        @endif
        <input type="file" accept="{{ $accept }}" multiple class="sr-only" data-action="change->photo-pdf#add">
    </label>
    <x-ui.sheet :id="$id" :title="$title">
        <div class="report-shots photo-pdf-pages" data-photo-pdf-target="pages"></div>
        <template data-photo-pdf-target="more">
            <label class="report-shot photo-pdf-page photo-pdf-more" aria-label="Добавить ещё"><x-ui.icon name="plus" class="size-6"/>
                <input type="file" accept="{{ $accept }}" multiple class="sr-only" data-action="change->photo-pdf#add">
            </label>
        </template>
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
            <button type="button" class="btn btn-accent w-full" data-photo-pdf-target="save" data-action="photo-pdf#save" disabled>Сохранить</button>
        </div>
    </x-ui.sheet>
</span>
