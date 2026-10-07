{{-- Покупатель для договора и счёта (05.10.2026): физлицо — паспорт (`x-billing.passport-fields`), организация — название,
     ИНН, КПП, ОГРН, адрес и руководитель. Вид — чипами, скрытая панель выключена (reveal) и в форму не уходит. Только в
     шторках (cabinet/deals/contract). 07.10.2026: поля организации строками (.fields). --}}
@props(['party' => null, 'prefix' => 'buyer', 'key' => null])
@php
    $key ??= $prefix;
    $kind = old($prefix.'.kind', $party?->kind === \App\Billing\PartyKind::Company ? 'company' : 'person');
    $n = fn (string $f) => $prefix.'['.$f.']';
    $id = fn (string $f) => $key.'-org-'.$f;
@endphp
<div class="flex flex-col gap-3" data-controller="reveal">
    <div class="flex flex-wrap gap-2">
        <label class="choice"><input type="radio" name="{{ $n('kind') }}" value="person" @checked($kind === 'person') data-action="reveal#pick"><span>Физлицо</span></label>
        <label class="choice"><input type="radio" name="{{ $n('kind') }}" value="company" @checked($kind === 'company') data-action="reveal#pick"><span>Организация</span></label>
    </div>
    <div data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
        <x-billing.passport-fields :party="$party" :prefix="$prefix" :key="$key" rows/>
    </div>
    <div class="fields" data-reveal-target="pane" data-reveal-key="company" @if ($kind !== 'company') hidden @endif>
        <x-ui.field :name="$n('name')" :id="$id('name')" label="Название" :value="$party?->name" required/>
        <x-ui.field :name="$n('inn')" :id="$id('inn')" label="ИНН" :value="$party?->inn" required/>
        <x-ui.field :name="$n('kpp')" :id="$id('kpp')" label="КПП" :value="$party?->kpp"/>
        <x-ui.field :name="$n('ogrn')" :id="$id('ogrn')" label="ОГРН" :value="$party?->ogrn"/>
        <x-ui.field :name="$n('director')" :id="$id('director')" label="Руководитель" :value="$party?->director" required/>
        <x-ui.field :name="$n('legal_address')" :id="$id('legal_address')" label="Юр. адрес" :value="$party?->legal_address" required/>
    </div>
</div>
