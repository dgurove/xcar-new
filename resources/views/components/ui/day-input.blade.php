{{-- Дата текстом «дд.мм.гггг» (14.11.2026, менеджеры в CRM и на сайте: «при вводе паспортных данных ОЧЕНЬ попросили не
     календарик, а текстовый ввод»): цифры набирают подряд, точки встают сами («11041990» → «11.04.1990»), свои точки тоже
     можно («1.4.1990»), год двумя цифрами дописывается на выходе из поля. В форму — скрытое поле прежнего вида (`Y-m-d`), как
     у `x-ui.date`: сервер не меняется; неверная дата или вне min/max — ошибка формы словами (`day_input_controller`). Зовёт
     `x-ui.field` для паспортных дат (`birth_at`, `passport_issued_at`); скан паспорта перерисовывает поле (`render`). --}}
@props(['name', 'id' => null, 'value' => null, 'min' => null, 'max' => null])
@php
    $id ??= 'f-'.str_replace(['[', ']'], ['-', ''], $name);
    $v = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    $text = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? $m[3].'.'.$m[2].'.'.$m[1] : '';
@endphp
<div class="contents" data-controller="day-input" @if ($min) data-day-input-min-value="{{ $min }}" @endif @if ($max) data-day-input-max-value="{{ $max }}" @endif>
    <input type="hidden" name="{{ $name }}" value="{{ $text === '' ? '' : $v }}" data-day-input-target="value" {{ $attributes->only(['form', 'disabled']) }}>
    <input type="text" id="{{ $id }}" class="field-input" inputmode="numeric" autocomplete="off" enterkeyhint="next" placeholder="дд.мм.гггг" maxlength="10"
        value="{{ $text }}" data-day-input-target="text" data-action="input->day-input#type blur->day-input#finish" {{ $attributes->only(['disabled']) }}>
</div>
