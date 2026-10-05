{{-- «Оценить → Из текста» (владелец, 05.10.2026): вставили сообщение с оценочными стоимостями — разбор этапами
     с анимацией (valuation_controller), итог группами и «Всё верно, сохранить». Сервер — OfferValuationController. --}}
@php
    $steps = ['clean' => 'Убираем лишнее', 'match' => 'Ищем предложения', 'drop' => 'Убираем ненайденные', 'value' => 'Ставим оценочные', 'floor' => 'Считаем закупочные', 'done' => 'Готово'];
@endphp
<x-ui.sheet id="valuation" title="Оценка из текста" tall class="sheet-valuation">
    <div class="valuation" data-controller="valuation" data-valuation-url-value="/offers/valuations/preview">
        <section class="valuation-pane" data-valuation-target="paste">
            <textarea class="field-input valuation-text" rows="6" placeholder="Вставьте сообщение страховой: номер убытка, сумма, VIN, город" autocomplete="off" spellcheck="false"
                      data-valuation-target="text" data-action="paste->valuation#pasted"></textarea>
            <x-ui.button type="button" block class="mt-3" data-action="valuation#run">Разобрать</x-ui.button>
        </section>

        <section class="valuation-work" data-valuation-target="work" hidden>
            <ol class="valuation-steps">
                @foreach ($steps as $key => $label)
                    <li class="valuation-step" data-step="{{ $key }}" data-state="wait">
                        <span class="valuation-mark"><span class="valuation-ring"></span><svg viewBox="0 0 24 24" class="valuation-tick"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                        <span class="flex-1">{{ $label }}</span>
                        <span class="nums text-sm text-ink-dim" data-count></span>
                    </li>
                @endforeach
            </ol>
            <div class="valuation-lines" data-valuation-target="lines"></div>
            <div class="mt-4" data-valuation-target="fail" hidden>
                <p class="text-ink-muted" data-valuation-target="reason"></p>
                <x-ui.button type="button" variant="secondary" block class="mt-3" data-action="valuation#reset">Вставить другой текст</x-ui.button>
            </div>
        </section>

        <form method="post" action="/offers/valuations" class="valuation-pane" data-valuation-target="result" hidden>
            @csrf
            <input type="hidden" name="token" data-valuation-target="token">
            <p class="valuation-summary" data-valuation-target="done" hidden>
                <svg viewBox="0 0 64 64" class="valuation-ok"><circle cx="32" cy="32" r="28"/><path d="M19 33l9 9 17-19"/></svg>
                <span data-valuation-target="summary"></span>
            </p>
            <div class="valuation-scroll" data-valuation-target="rows"></div>
            <div class="valuation-bar">
                <x-ui.button type="button" variant="secondary" class="shrink-0" data-action="valuation#reset sheet#close">Отменить</x-ui.button>
                <x-ui.button class="min-w-0 flex-1" data-valuation-target="save">Всё верно, сохранить</x-ui.button>
            </div>
        </form>
    </div>
</x-ui.sheet>
