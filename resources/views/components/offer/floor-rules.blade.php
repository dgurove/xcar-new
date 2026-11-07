{{-- Шкала наценки закупочной к оценочной (07.11.2026; шкалой — 08.11.2026, владелец: строки «до [ ] + [ ] [₽▾] ×» —
     «позорище», начальник: «шкалы красивые, динамичные, как это обычно в таких системах делают»). Полоса — сегмент на
     диапазон с его наценкой, ширины по корню от суммы; ручки между сегментами тянутся (шаг круглый, за соседей не заходят,
     стрелками — тоже); нажатие по сегменту — под полосой его правка: «до [граница] · [наценка] [₽ | %]», «Разделить»,
     «Убрать». Вписали оценочную — по полосе едет метка «700 000 → 770 000», её сегмент горит. Рисует `floor_rules_controller`
     из ступеней; в форму — одно скрытое поле `name` (JSON [[до, ₽, %], …], сервер приводит его `Pricing::clean`), событие
     `floor-rules:change` — закупочную пересчитывает `value-floor`. Одна на редактор партнёра, «Цены» CRM и шторку вендора. --}}
@props(['rules', 'default' => null, 'name' => 'floor_rules', 'form' => null, 'id' => 'floor-rules', 'label' => 'Наценка'])
@php $default ??= $rules; @endphp
<div class="floor-scale" id="{{ $id }}" data-controller="floor-rules" data-floor-rules-rules-value='@json($rules)' data-floor-rules-default-value='@json($default)'>
    <input type="hidden" name="{{ $name }}" value='@json($rules)' data-floor-rules-target="input" @if ($form) form="{{ $form }}" @endif>
    <div class="floor-scale-head">
        <span class="floor-scale-label">{{ $label }}</span>
        <button type="button" class="floor-scale-reset" data-floor-rules-target="reset" data-action="floor-rules#reset" @if ($rules === $default) hidden @endif>Как по умолчанию</button>
    </div>
    {{-- Метка оценочной над полосой; полоса с сегментами и ручками; подписи границ под ней — всё рисует контроллер. --}}
    <div class="floor-scale-track" data-floor-rules-target="track">
        <div class="floor-scale-pin" data-floor-rules-target="pin" hidden><span class="floor-scale-pin-text nums" data-floor-rules-target="pinText"></span></div>
        <div class="floor-scale-bar" data-floor-rules-target="bar"></div>
        <div class="floor-scale-edges nums" data-floor-rules-target="edges"></div>
    </div>
    <div class="floor-scale-edit" data-floor-rules-target="editor">
        <label class="floor-scale-cell" data-floor-rules-target="belowBox">
            <span>до</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="nums" aria-label="Граница диапазона, ₽" data-floor-rules-target="below"
                data-controller="digits" data-action="input->digits#format change->floor-rules#setBelow keydown.enter->floor-rules#setBelow:prevent">
        </label>
        <label class="floor-scale-cell">
            <span>+</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="nums" aria-label="Наценка" data-floor-rules-target="amount" data-action="input->floor-rules#setAmount">
        </label>
        <div class="floor-scale-unit" role="group" aria-label="Рубли или проценты">
            <button type="button" data-floor-rules-target="unit" data-unit="rub" data-action="floor-rules#setUnit">₽</button>
            <button type="button" data-floor-rules-target="unit" data-unit="pct" data-action="floor-rules#setUnit">%</button>
        </div>
        <div class="floor-scale-acts">
            <button type="button" data-action="floor-rules#split">Разделить</button>
            <button type="button" data-floor-rules-target="remove" data-action="floor-rules#remove">Убрать</button>
        </div>
    </div>
</div>
