{{-- «Оценить → Из текста» (владелец, 05.10.2026): вставили сообщение с оценочными стоимостями — разбор этапами
     с анимацией (valuation_controller), итог группами и «Сохранить». Сервер — OfferValuationController.
     Фото или скриншот (22.10.2026) — OCR даёт текст «номер сумма» (`ValuationPhoto`), таблица Excel (25.10.2026, Альфа
     присылает файлом) — `ValuationSheet`; уверенное — сразу в разбор.
     25.10.2026, владелец: «как полноценное приложение»: первый экран — источник строками («Вставить текст», «Таблица
     Excel», «Фото или скриншот»), а не пустое поле; файл — карточкой с ходом чтения; итог — сегментом по группам; после
     сохранения — экран «Готово». Перетаскивание и ⌘V — в любом месте окна. --}}
@php
    $steps = ['clean' => 'Убираем лишнее', 'match' => 'Ищем предложения', 'drop' => 'Убираем ненайденные', 'value' => 'Ставим оценочные', 'floor' => 'Считаем закупочные', 'done' => 'Готово'];
    $ring = '<span class="valuation-mark"><span class="valuation-ring"></span><svg viewBox="0 0 24 24" class="valuation-tick"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>';
@endphp
<x-ui.sheet id="valuation" title="Оценка" tall class="sheet-valuation">
    <div class="valuation" data-controller="valuation" data-valuation-url-value="/offers/valuations/preview" data-valuation-file-url-value="/offers/valuations/file"
         data-action="dragover->valuation#dragOver dragleave->valuation#dragLeave drop->valuation#drop paste@window->valuation#pasted">
        {{-- 1. Откуда: источник, файл, текст. --}}
        <section class="valuation-pane" data-valuation-target="paste">
            <div class="valuation-body">
                <div class="list valuation-sources" data-valuation-target="sources">
                    <button type="button" class="row" data-action="valuation#pasteText">
                        <x-ui.row-icon name="chat" tone="soft"/>
                        <span class="min-w-0 flex-1 text-left">Вставить текст</span>
                        <kbd class="valuation-key">⌘V</kbd>
                    </button>
                    <label class="row">
                        <x-ui.row-icon name="table" tone="soft"/>
                        <span class="min-w-0 flex-1">Таблица Excel</span>
                        <x-ui.chevron/>
                        <input type="file" accept=".xlsx,.xls,.csv,.ods" class="sr-only" data-action="change->valuation#pick">
                    </label>
                    <label class="row">
                        <x-ui.row-icon name="photo" tone="soft"/>
                        <span class="min-w-0 flex-1">Фото или скриншот</span>
                        <x-ui.chevron/>
                        <input type="file" accept="image/*" class="sr-only" data-action="change->valuation#pick">
                    </label>
                </div>

                {{-- Файл: значок или миниатюра, имя, объём или ход; справа кольцо, галка или «×». --}}
                <div class="list" data-valuation-target="file" hidden>
                    <div class="row valuation-file" data-state="read">
                        <span class="valuation-file-icon" data-valuation-target="fileIcon"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate" data-valuation-target="fileName"></span>
                            <span class="row-sub" data-valuation-target="fileSub"></span>
                        </span>
                        {!! $ring !!}
                        <button type="button" class="sheet-close valuation-file-x" data-action="valuation#reset" aria-label="Убрать файл"><x-ui.icon name="x" class="size-[18px]"/></button>
                    </div>
                </div>

                <div class="valuation-editor" data-valuation-target="editor" hidden>
                    <p class="valuation-note" data-valuation-target="note" hidden>Проверьте суммы и нажмите «Разобрать»</p>
                    <textarea class="field-input valuation-text" rows="3" placeholder="Номер убытка, сумма, VIN, город" autocomplete="off" spellcheck="false"
                              data-valuation-target="text" data-action="input->valuation#typed"></textarea>
                </div>
            </div>
            <div class="valuation-bar" data-valuation-target="runBar" hidden>
                <x-ui.button type="button" variant="secondary" class="shrink-0" data-action="valuation#reset">Очистить</x-ui.button>
                <x-ui.button type="button" class="min-w-0 flex-1" data-action="valuation#run">Разобрать</x-ui.button>
            </div>
        </section>

        {{-- 2. Разбор этапами. --}}
        <section class="valuation-work" data-valuation-target="work" hidden>
            <ol class="valuation-steps">
                @foreach ($steps as $key => $label)
                    <li class="valuation-step" data-step="{{ $key }}" data-state="wait">
                        {!! $ring !!}
                        <span class="flex-1">{{ $label }}</span>
                        <span class="nums text-sm text-ink-dim" data-count></span>
                    </li>
                @endforeach
            </ol>
            <div class="valuation-lines" data-valuation-target="lines"></div>
            <div class="mt-4" data-valuation-target="fail" hidden>
                <p class="text-ink-muted" data-valuation-target="reason"></p>
                <x-ui.button type="button" variant="secondary" block class="mt-3" data-action="valuation#reset">Попробовать другое</x-ui.button>
            </div>
        </section>

        {{-- 3. Что изменится: сегмент по группам, карточки, «Сохранить». --}}
        <form method="post" action="/offers/valuations" class="valuation-pane" data-valuation-target="result" data-action="submit->valuation#save" hidden>
            @csrf
            <input type="hidden" name="token" data-valuation-target="token">
            <div class="valuation-scroll" data-valuation-target="rows"></div>
            <p class="valuation-what" data-valuation-target="what"></p>
            <div class="valuation-bar">
                <x-ui.button type="button" variant="secondary" class="shrink-0" data-action="valuation#reset sheet#close">Отменить</x-ui.button>
                <x-ui.button class="min-w-0 flex-1" data-valuation-target="save">Сохранить</x-ui.button>
            </div>
        </form>

        {{-- 4. Готово. --}}
        <section class="valuation-pane valuation-done" data-valuation-target="done" hidden>
            <div class="valuation-done-body">
                <svg viewBox="0 0 64 64" class="valuation-ok"><circle cx="32" cy="32" r="28"/><path d="M19 33l9 9 17-19"/></svg>
                <h3 data-valuation-target="doneTitle"></h3>
                <p class="text-ink-muted" data-valuation-target="doneSub"></p>
            </div>
            {{-- Заведённые с Мигторга — строками, проявляются по одной: номер печатается, закупочная бежит. --}}
            <div class="list valuation-created" data-valuation-target="created" hidden></div>
            {{-- Номер убытка заведённых — с копированием, как в строках (`x-ui.copy-code`): строку собирает скрипт. --}}
            <template data-valuation-target="copyCode"><x-ui.copy-code value="0"/></template>
            <div class="valuation-bar">
                <x-ui.button type="button" class="min-w-0 flex-1" data-action="valuation#finish sheet#close">Готово</x-ui.button>
            </div>
        </section>
    </div>
</x-ui.sheet>
