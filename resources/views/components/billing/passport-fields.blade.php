{{-- Паспорт физлица для договора (ДКП, 05.10.2026): ФИО, рождение, серия и номер, кем, когда и код подразделения,
     адрес регистрации. prefix — имя набора в форме (`seller[...]`, `buyer[...]`), правила — `PartyRules::passport`;
     key — начало id полей, когда два набора с одним prefix на странице. rows — строками, как в Настройках (.fields, в
     шторке договора, 07.10.2026); на страницах — сеткой. В строках все поля одним видом — подпись сверху, значение под ней
     во всю ширину (`.fields-stack`, 05.11.2026, владелец: ФИО резалось «Макаров Дмит…», а смесь строк и столбиков —
     «скачут размеры и подписи»); длинное (место рождения, кем выдан, адрес) переносится. fio=false — без ФИО, bare — без
     своей плашки (`x-billing.buyer-fields`: ФИО у физлица и ИП одно, паспорт — только у физлица, 06.11.2026). --}}
@props(['party' => null, 'prefix', 'key' => null, 'rows' => false, 'fio' => true, 'bare' => false])
@php
    $n = fn (string $f) => $prefix.'['.$f.']'; $id = fn (string $f) => ($key ?? $prefix).'-'.$f; $g = $rows ? 'contents' : 'grid grid-cols-1 gap-3 sm:grid-cols-2';
    $long = $rows ? 'textarea' : 'text';
@endphp
<div class="{{ $bare ? 'contents' : ($rows ? 'fields fields-stack' : 'flex flex-col gap-3') }}">
    @if ($fio)<x-ui.field :name="$n('name')" :id="$id('name')" label="ФИО" :value="$party?->name" required/>@endif
    <div class="{{ $g }}">
        <x-ui.field :name="$n('birth_at')" :id="$id('birth_at')" label="Дата рождения" type="date" :value="$party?->birth_at?->toDateString()" required/>
        <x-ui.field :name="$n('birth_place')" :id="$id('birth_place')" label="Место рождения" :type="$long" rows="1" :value="$party?->birth_place"/>
        {{-- Серия и номер — двумя полями одной строкой, без образцов (07.11.2026, владелец: полупрозрачное «36 05 350251»
             приняли за криво распознанное); склеивает сервер (`PartyRules::squeeze`). --}}
        @php
            [$series, $number] = \App\Billing\PartyRules::passportParts($party?->passport);
            $dot = fn (string $f) => str_replace(['[', ']'], ['.', ''], $n($f));
            $passportError = $errors->first($dot('passport'));
        @endphp
        <div class="field {{ $passportError ? 'field-invalid' : '' }}">
            <label for="{{ $id('passport_series') }}" class="field-label">Паспорт<x-ui.req/></label>
            <div class="passport-no">
                <label class="passport-no-part" for="{{ $id('passport_series') }}">серия</label>
                <input id="{{ $id('passport_series') }}" name="{{ $n('passport_series') }}" value="{{ old($dot('passport_series'), $series) }}" class="field-input passport-no-series" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="12">
                <label class="passport-no-part" for="{{ $id('passport_number') }}">номер</label>
                <input id="{{ $id('passport_number') }}" name="{{ $n('passport_number') }}" value="{{ old($dot('passport_number'), $number) }}" class="field-input passport-no-number" inputmode="decimal" autocomplete="off" autocorrect="off" spellcheck="false" maxlength="30">
            </div>
            @if ($passportError)<p class="field-error">{{ $passportError }}</p>@endif
        </div>
        <x-ui.field :name="$n('passport_issued_at')" :id="$id('passport_issued_at')" label="Дата выдачи" type="date" :value="$party?->passport_issued_at?->toDateString()" required/>
        <x-ui.field :name="$n('passport_issued')" :id="$id('passport_issued')" label="Кем выдан" :type="$long" rows="1" :value="$party?->passport_issued" span="sm:col-span-2" required/>
        <x-ui.field :name="$n('passport_code')" :id="$id('passport_code')" label="Код подразделения" :value="$party?->passport_code"/>
        <x-ui.field :name="$n('reg_address')" :id="$id('reg_address')" label="Адрес регистрации" :type="$long" rows="1" :value="$party?->reg_address" span="sm:col-span-2" required/>
    </div>
</div>
