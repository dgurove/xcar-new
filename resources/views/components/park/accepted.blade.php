{{-- Дата приёма — одна на окошко «Наличия», шапку и ленту дела, выдачу и «Указать парковку» (02.11.2026, владелец: «многие
     даты приёма неверные… менять безумно неудобно, и Серёге, и админу»). Правят все люди парковки (`User::parkDates`: администратор,
     управляющий, приёмщик), остальным — текстом. Нажатие — календарь со временем и подсказками дня из писем
     (`Park\AcceptHints`: «Заявка 02.09», «Отчёт 06.09») — одно нажатие на подсказку и дата стоит.
     `submit` — своя форма (`x-park.date-forms`, `POST /cars/{v}/dates`), дата — пунктиром внутри фразы; `field` — поле
     `accepted_at` чужой формы (выдача, «Указать парковку»): строка «Принята», под ней подсказки чипами без календаря.
     `key` — у второй даты на той же странице (шапка и лента дела) свой id; `form` — своя форма (строка таблицы: форма карточки
     рядом с тем же id ушла бы мимо фрейма). --}}
@props(['vehicle', 'mode' => 'submit', 'key' => 'at', 'form' => null])
@php
    $edit = auth()->user()?->parkDates() && $vehicle->accepted_at;
    $hints = $edit ? \App\Park\AcceptHints::for($vehicle) : [];
@endphp
@if (! $edit)
    @if ($vehicle->accepted_at)<span {{ $attributes->only('class') }}>{{ \App\Support\Ago::day($vehicle->accepted_at, true) }}</span>@endif
@elseif ($mode === 'field')
    <div class="row flex-wrap justify-between gap-y-2">
        <label for="f-accepted-{{ $vehicle->id }}" class="text-ink-muted">Принята</label>
        <x-ui.date chip time :clearable="false" name="accepted_at" :id="'f-accepted-'.$vehicle->id" :value="old('accepted_at', $vehicle->accepted_at)"
            :max="now()" :quick="$hints" inline source="accepted_source" class="nums underline decoration-dotted decoration-ink-dim underline-offset-4"/>
    </div>
    @error('accepted_at')<p class="field-error px-4 pb-2">{{ $message }}</p>@enderror
@else
    <x-ui.date chip submit time :clearable="false" name="accepted_at" :id="'de-accepted_at-'.$vehicle->id.'-'.$key" :value="$vehicle->accepted_at"
        :max="now()" :quick="$hints" source="source" :form="$form ?? 'dates-'.$vehicle->id.'-accepted_at'" {{ $attributes->only('class') }}/>
@endif
