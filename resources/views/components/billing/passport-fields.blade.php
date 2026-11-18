{{-- Паспорт физлица — ОДИН кусок на сайте и в CRM (договор, счёт, «Ваши данные», карточка человека, продавец ДКП; 18.11.2026,
     владелец: «в CRM и в xcar форму одинаковой», «нравится, что поля сверху подписаны»). Поля — подпись сверху, значение в своей
     плашке, сеткой в две колонки; порядок владельца: ФИО → дата рождения (и место — его печатает ДКП ПРАЙМ) → серия | номер →
     кем выдан → код подразделения | дата выдачи → адрес регистрации. Даты — текстом, точки сами (`x-ui.day-input` через
     `x-ui.field`). Серию и номер склеивает сервер (`PartyRules::squeeze`, без набора — `squeezePassport`) в «8207 445349».
     prefix — имя набора (`seller[...]`, `buyer[...]`), пусто — поля без набора (`x-billing.party-fields`); key — начало id;
     fio=false — без ФИО (его даёт форма выше); bare — без своей обёртки. --}}
@props(['party' => null, 'prefix' => null, 'key' => null, 'fio' => true, 'bare' => false, 'required' => true])
@php
    $n = fn (string $f) => $prefix ? $prefix.'['.$f.']' : $f;
    $dot = fn (string $f) => $prefix ? $prefix.'.'.$f : $f;
    $id = fn (string $f) => ($key ?? $prefix ?? 'party').'-'.$f;
    [$series, $number] = \App\Billing\PartyRules::passportParts($party?->passport);
    $passportError = $errors->first($dot('passport'));
@endphp
<div class="{{ $bare ? 'contents' : 'flex flex-col gap-3' }}">
    <div class="passport-grid grid grid-cols-2 gap-3">
        @if ($fio)<x-ui.field :name="$n('name')" :id="$id('name')" label="ФИО" :value="$party?->name" span="col-span-2" :required="$required"/>@endif
        <x-ui.field :name="$n('birth_at')" :id="$id('birth_at')" label="Дата рождения" type="date" :value="$party?->birth_at?->toDateString()" :required="$required"/>
        <x-ui.field :name="$n('birth_place')" :id="$id('birth_place')" label="Место рождения" :value="$party?->birth_place"/>
        <div class="field {{ $passportError ? 'field-invalid' : '' }}">
            <label for="{{ $id('passport_series') }}" class="field-label">Серия@if ($required)<x-ui.req/>@endif</label>
            <input id="{{ $id('passport_series') }}" name="{{ $n('passport_series') }}" value="{{ old($dot('passport_series'), $series) }}" class="field-input" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="12">
        </div>
        <div class="field {{ $passportError ? 'field-invalid' : '' }}">
            <label for="{{ $id('passport_number') }}" class="field-label">Номер@if ($required)<x-ui.req/>@endif</label>
            <input id="{{ $id('passport_number') }}" name="{{ $n('passport_number') }}" value="{{ old($dot('passport_number'), $number) }}" class="field-input" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="30">
        </div>
        @if ($passportError)<p class="field-error col-span-2 -mt-2">{{ $passportError }}</p>@endif
        <x-ui.field :name="$n('passport_issued')" :id="$id('passport_issued')" label="Кем выдан" type="textarea" rows="1" :value="$party?->passport_issued" span="col-span-2" :required="$required"/>
        <x-ui.field :name="$n('passport_code')" :id="$id('passport_code')" label="Код подразделения" :value="$party?->passport_code"/>
        <x-ui.field :name="$n('passport_issued_at')" :id="$id('passport_issued_at')" label="Дата выдачи" type="date" :value="$party?->passport_issued_at?->toDateString()" :required="$required"/>
        <x-ui.field :name="$n('reg_address')" :id="$id('reg_address')" label="Адрес регистрации" type="textarea" rows="1" :value="$party?->reg_address" span="col-span-2" :required="$required"/>
    </div>
</div>
