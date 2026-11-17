{{-- «Текст счёта» документа месяца (17.11.2026, владелец: «невозможно изменить текст наименования услуги, нужно дату акта
     скорректировать»; счёт Альфе от 17.11, акт — № 20261109-01 от 09.11.2026): номер и дата акта, основание и строка счёта.
     Номер и дата акта пустые — как у счёта (подсказка внутри). Основание и строку правят готовым текстом; совпал с собранным —
     своего нет, и правка одного акта меняет строку сама (`Invoice::registryLine`).
     Черновик — `ClosingText` по месяцу и группе, записанный счёт — свои колонки и заново файлы акта (`ClosingTextController`). --}}
@php
    /** @var \App\Billing\Invoice $shown */
    $mine = old('_text') === $doc->key;
@endphp
<form method="post" action="/money/closing/{{ $doc->key }}/text" class="flex flex-col gap-4">
    @csrf
    <input type="hidden" name="_text" value="{{ $doc->key }}">
    <div class="fields">
        <x-ui.field name="act_no" label="Номер акта" :value="$mine ? old('act_no') : $shown->act_no" :placeholder="$shown->docNo()" :id="'text-act-no-'.$doc->key"/>
        <x-ui.field name="act_at" label="Дата акта" type="date" :value="$mine ? old('act_at') : $shown->act_at?->format('Y-m-d')" :placeholder="$shown->issued_at?->format('d.m.Y')" :id="'text-act-at-'.$doc->key"/>
    </div>
    <div class="fields">
        <x-ui.field name="basis" label="Основание" type="textarea" :value="$mine ? old('basis') : $shown->basisText()" :id="'text-basis-'.$doc->key"/>
    </div>
    @if ($shown->isRegistry())
        <div class="fields">
            <x-ui.field name="line_title" label="Наименование услуги" type="textarea" :value="$mine ? old('line_title') : $shown->registryLine()" :id="'text-line-'.$doc->key"/>
        </div>
    @endif
    <x-ui.sheet-foot>Сохранить</x-ui.sheet-foot>
</form>
