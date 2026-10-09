{{-- Выбор даты (и времени) вместо системного поля — `date_controller`, календарь Cally. Видно — дата словами, в форму уходит
     скрытое поле прежнего вида (`Y-m-d`, со временем `Y-m-d\TH:i`): сервер не меняется. Свои атрибуты (`data-action`,
     `disabled`, `form`) — у скрытого поля, `disabled` ещё и у видимого. Время по умолчанию — `default-time` (CRM срок
     приёма 17:30, парковка 12:00). Зовёт его `x-ui.field` с `type="date|datetime-local"`. --}}
@props(['name', 'id' => null, 'value' => null, 'time' => false, 'defaultTime' => '12:00', 'min' => null, 'max' => null, 'placeholder' => null, 'clearable' => true])
@php
    $id ??= 'f-'.str_replace(['[', ']'], ['-', ''], $name);
    // Прежние значения бывают с секундами и пробелом («2026-10-09 17:30:00») — к виду поля.
    $v = $value instanceof \DateTimeInterface ? $value->format($time ? 'Y-m-d\TH:i' : 'Y-m-d') : (string) $value;
    $v = $v === '' ? '' : ($time ? substr(str_replace(' ', 'T', $v), 0, 16) : substr($v, 0, 10));
    $day = fn ($d) => $d ? substr(str_replace(' ', 'T', (string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d)), 0, 10) : null;
    $disabled = $attributes->has('disabled') && $attributes->get('disabled') !== false;
@endphp
<div class="date-field" data-controller="date" data-date-time-value="{{ $time ? 'true' : 'false' }}" data-date-default-time-value="{{ $defaultTime }}"
    data-date-min-value="{{ $day($min) }}" data-date-max-value="{{ $day($max) }}">
    <input type="hidden" name="{{ $name }}" value="{{ $v }}" data-date-target="value" {{ $attributes->except(['class', 'id', 'type', 'placeholder']) }}>
    <input type="text" id="{{ $id }}" class="field-input date-input {{ $attributes->get('class') }}" autocomplete="off" spellcheck="false"
        placeholder="{{ $placeholder ?? ($time ? 'Дата и время' : 'Дата') }}" @disabled($disabled) data-date-target="text"
        data-action="click->date#open keydown->date#typed change->date#typed">
    <div class="date-pop" popover data-date-target="pop">
        <calendar-date class="date-cal" locale="ru-RU" first-day-of-week="1" format-weekday="short" data-date-target="cal" data-action="change->date#pick"
            @if ($day($min)) min="{{ $day($min) }}" @endif @if ($day($max)) max="{{ $day($max) }}" @endif>
            <x-ui.icon slot="previous" name="chevron-down" class="size-5 rotate-90" aria-label="Прошлый месяц"/>
            <x-ui.icon slot="next" name="chevron-down" class="size-5 -rotate-90" aria-label="Следующий месяц"/>
            <div slot="heading" class="date-heading"><calendar-select-month format-month="long"><span slot="label">Месяц</span></calendar-select-month><calendar-select-year><span slot="label">Год</span></calendar-select-year></div>
            <calendar-month></calendar-month>
        </calendar-date>
        {{-- Необязательную дату можно стереть («Очистить»): на телефоне поле только открывает календарь. --}}
        @if ($time || $clearable)
            <div class="date-foot">
                @if ($time)<label class="date-time"><span class="sr-only">Время</span><input type="text" inputmode="numeric" maxlength="5" class="field-input" data-date-target="time" data-action="focus->date#lift input->date#typeTime keydown.enter->date#done:prevent"></label>@endif
                @if ($clearable)<button type="button" class="btn btn-quiet {{ $time ? '' : 'ml-auto' }}" data-date-target="clear" data-action="date#clear">Очистить</button>@endif
                @if ($time)<button type="button" class="btn btn-accent" data-action="date#done">Готово</button>@endif
            </div>
        @endif
    </div>
    <p class="field-error" data-date-target="error" hidden></p>
</div>
