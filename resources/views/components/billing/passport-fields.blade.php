{{-- Паспорт физлица для договора (ДКП, 05.10.2026): ФИО, рождение, серия и номер, кем, когда и код подразделения,
     адрес регистрации. prefix — имя набора в форме (`seller[...]`, `buyer[...]`), правила — `PartyRules::passport`;
     key — начало id полей, когда два набора с одним prefix на странице. --}}
@props(['party' => null, 'prefix', 'key' => null])
@php $n = fn (string $f) => $prefix.'['.$f.']'; $id = fn (string $f) => ($key ?? $prefix).'-'.$f; $g = 'grid grid-cols-1 gap-3 sm:grid-cols-2'; @endphp
<div class="flex flex-col gap-3">
    <x-ui.field :name="$n('name')" :id="$id('name')" label="ФИО" :value="$party?->name" required/>
    <div class="{{ $g }}">
        <x-ui.field :name="$n('birth_at')" :id="$id('birth_at')" label="Дата рождения" type="date" :value="$party?->birth_at?->toDateString()" required/>
        <x-ui.field :name="$n('birth_place')" :id="$id('birth_place')" label="Место рождения" :value="$party?->birth_place"/>
        <x-ui.field :name="$n('passport')" :id="$id('passport')" label="Паспорт, серия и номер" :value="$party?->passport" placeholder="36 05 350251" required/>
        <x-ui.field :name="$n('passport_issued_at')" :id="$id('passport_issued_at')" label="Дата выдачи" type="date" :value="$party?->passport_issued_at?->toDateString()" required/>
        <x-ui.field :name="$n('passport_issued')" :id="$id('passport_issued')" label="Кем выдан" :value="$party?->passport_issued" span="sm:col-span-2" required/>
        <x-ui.field :name="$n('passport_code')" :id="$id('passport_code')" label="Код подразделения" :value="$party?->passport_code" placeholder="632-032"/>
        <x-ui.field :name="$n('reg_address')" :id="$id('reg_address')" label="Адрес регистрации" :value="$party?->reg_address" span="sm:col-span-2" required/>
    </div>
</div>
