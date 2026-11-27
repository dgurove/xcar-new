{{-- Окно «Вы уверены?» перед «Беру в работу» и «Осмотрел, берём» (19.11.2026, начальник; текст `Offers\Warnings::TAKE` дословно).
     Три кнопки: лаймовая «Да, беру в работу»; «Ещё подумаю…» — экран «Напомнить через» (`x-deal.remind-list`, строка ставит
     напоминание и закрывает); «Отказываюсь» — своё «Вы уверены?» (`Warnings::DEAL_OFF`) с красной «Да, отказываюсь», ответ —
     исход отказа той же формой. Вид — как у `confirm.js`: на телефоне снизу, на ПК окном. Открывает событие `take:<id>`
     (кнопка с `emit#send`, `take_sheet_controller`); стоит вне формы ответа — у напоминания свои формы, ответ уходит атрибутом `form`.
     form — id формы ответа; take / drop — [имя, значение] кнопки ответа и отказа (отказа нет — `null`); ask — вопрос шага или
     доп. просьба для напоминания (нет — «Ещё подумаю» просто закрывает).
     want — id шторки «Доп. запрос…» (27.11.2026, Корабельников: «как отказа быть не может, если я запрашиваю осмотр»): кнопка
     «Сначала доп. запрос…» закрывает окно и открывает её. early — доп. просьба ещё без ответа поставщика: строка `TAKE_EARLY`. --}}
@props(['id', 'form', 'take', 'ok' => 'Да, беру в работу', 'drop' => null, 'deal' => null, 'ask' => null, 'extra' => false, 'back' => null, 'want' => null, 'early' => false])
@php
    use App\Offers\Warnings;
    $later = $deal && $ask;
@endphp
<dialog id="{{ $id }}" class="sheet" data-controller="take-sheet" data-action="take:{{ $id }}@window->take-sheet#open click->take-sheet#backdrop">
    <div data-take-sheet-target="pane" data-pane="main">
        <h2 class="mb-5 text-lg">{{ Warnings::TITLE }}</h2>
        @if ($early)<p class="-mt-3 mb-5 text-ink">{{ Warnings::TAKE_EARLY }}</p>@endif
        <p @class(['mb-5 whitespace-pre-line text-ink-muted', '-mt-3' => ! $early])>{{ Warnings::TAKE }}</p>
        <div class="flex flex-col gap-2">
            <button type="submit" form="{{ $form }}" name="{{ $take[0] }}" value="{{ $take[1] }}" class="btn btn-accent btn-block" data-action="take-sheet#send">{{ $ok }}</button>
            @if ($want)
                <button type="button" class="btn btn-quiet btn-block" data-controller="emit" data-action="take-sheet#close emit#send" data-emit-event-param="{{ $want }}:open">Сначала доп. запрос…</button>
            @endif
            @if ($later)
                <button type="button" class="btn btn-quiet btn-block" data-action="take-sheet#later">{{ Warnings::CANCEL }}…</button>
            @else
                <button type="button" class="btn btn-quiet btn-block" data-action="take-sheet#close">{{ Warnings::CANCEL }}</button>
            @endif
            @if ($drop)
                <button type="button" class="btn btn-quiet btn-block" data-action="take-sheet#refuse">Отказываюсь</button>
            @endif
        </div>
    </div>
    @if ($later)
        <div data-take-sheet-target="pane" data-pane="later" hidden>
            <div class="sheet-bar mb-4 flex items-center gap-2">
                <button type="button" class="sheet-close -ml-2" data-action="take-sheet#main" aria-label="Назад"><x-ui.icon name="chevron-left" class="size-5"/></button>
                <h2 class="text-lg">Напомнить через</h2>
            </div>
            <x-deal.remind-list :deal="$deal" :ask="$ask" :extra="$extra" :back="$back"/>
        </div>
    @endif
    @if ($drop)
        <div data-take-sheet-target="pane" data-pane="refuse" hidden>
            <h2 class="mb-5 text-lg">{{ Warnings::TITLE }}</h2>
            <p class="-mt-3 mb-5 whitespace-pre-line text-ink-muted">{{ Warnings::DEAL_OFF }}</p>
            <div class="flex flex-col gap-2">
                <button type="submit" form="{{ $form }}" name="{{ $drop[0] }}" value="{{ $drop[1] }}" class="btn btn-danger btn-block" data-action="take-sheet#send">Да, отказываюсь</button>
                <button type="button" class="btn btn-quiet btn-block" data-action="take-sheet#main">{{ Warnings::CANCEL }}</button>
            </div>
        </div>
    @endif
</dialog>
