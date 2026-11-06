{{-- Покупатель для договора и счёта (05.10.2026; ИП — 06.11.2026, владелец: «физлицо, которое платит со своего ИП»):
     сегмент «Физлицо · ИП · Организация», ниже группы полей по виду. Скан паспорта — у физлица и ИП (поставщику идёт PDF
     со сканом), ФИО у них одно поле, паспорт — только у физлица, у ИП — ИНН, ОГРНИП, адрес и банк, у организации —
     название (своё поле `org`), ИНН, КПП, ОГРН, руководитель, адрес и банк по желанию. Скрытые группы выключены
     (reveal) и в форму не уходят, набранное в них остаётся — вернулись к виду, всё на месте; сервер пишет только поля
     выбранного вида (`SaveBuyerData`), так что и сохранённое при смене вида не теряется. Реквизиты, вставленные целиком
     в любое поле ИП или организации, раскладываются по полям (`requisites_paste_controller`).
     scan — id формы: плитки скана внутри группы (OCR вписывает в её поля); fallback — ФИО, когда в имени уже название
     организации (покупатель был организацией). Только в шторках и на экране «Данные для договора». --}}
@props(['party' => null, 'prefix' => 'buyer', 'key' => null, 'scan' => null, 'fallback' => null])
@php
    use App\Billing\PartyKind;
    $key ??= $prefix;
    $kind = old($prefix.'.kind', match ($party?->kind) { PartyKind::Company => 'company', PartyKind::Entrepreneur => 'ip', default => 'person' });
    $company = $party?->kind === PartyKind::Company;
    $n = fn (string $f) => $prefix.'['.$f.']';
    $id = fn (string $g, string $f) => $key.'-'.$g.'-'.$f;
    // Значения своего вида: ИНН и ОГРН организации — не ИП того же человека, и наоборот.
    $mine = fn (string $f, bool $own) => $own ? $party?->{$f} : null;
    $ip = $party?->kind === PartyKind::Entrepreneur;
@endphp
<div class="flex flex-col gap-3" data-controller="reveal requisites-paste" data-requisites-paste-prefix-value="{{ $prefix }}">
    <x-ui.segment :name="$n('kind')" :options="['person' => 'Физлицо', 'ip' => 'ИП', 'company' => 'Организация']" :value="$kind" pick/>
    @if ($scan && $party?->exists)
        <div data-reveal-target="pane" data-reveal-key="person ip" @if ($kind === 'company') hidden @endif>
            <x-billing.passport-scan :party="$party" :form="$scan" :prefix="$prefix"/>
        </div>
    @endif
    <div class="fields fields-stack" data-reveal-target="pane" data-reveal-key="person ip" @if ($kind === 'company') hidden @endif>
        <x-ui.field :name="$n('name')" :id="$id('p', 'name')" label="ФИО" :value="$company ? $fallback : $party?->name" required/>
        <div class="contents" data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
            <x-billing.passport-fields :party="$party" :prefix="$prefix" :key="$key" rows bare :fio="false"/>
        </div>
    </div>
    <div class="fields fields-stack" data-reveal-target="pane" data-reveal-key="ip" data-requisites-paste-target="group" @if ($kind !== 'ip') hidden @endif>
        <x-ui.field :name="$n('inn')" :id="$id('ip', 'inn')" label="ИНН" :value="$mine('inn', $ip)" placeholder="12 цифр" required/>
        <x-ui.field :name="$n('ogrn')" :id="$id('ip', 'ogrn')" label="ОГРНИП" :value="$mine('ogrn', $ip)" placeholder="15 цифр" required/>
        <x-ui.field :name="$n('legal_address')" :id="$id('ip', 'legal_address')" label="Адрес" type="textarea" rows="1" :value="$ip ? ($party->legal_address ?: $party->reg_address) : $party?->reg_address" required/>
    </div>
    <div class="fields fields-stack" data-reveal-target="pane" data-reveal-key="company" data-requisites-paste-target="group" @if ($kind !== 'company') hidden @endif>
        <x-ui.field :name="$n('org')" :id="$id('org', 'name')" label="Название" :value="$company ? $party->name : null" required/>
        <x-ui.field :name="$n('inn')" :id="$id('org', 'inn')" label="ИНН" :value="$mine('inn', $company)" required/>
        <x-ui.field :name="$n('kpp')" :id="$id('org', 'kpp')" label="КПП" :value="$mine('kpp', $company)"/>
        <x-ui.field :name="$n('ogrn')" :id="$id('org', 'ogrn')" label="ОГРН" :value="$mine('ogrn', $company)"/>
        <x-ui.field :name="$n('director')" :id="$id('org', 'director')" label="Руководитель" :value="$mine('director', $company)" required/>
        <x-ui.field :name="$n('legal_address')" :id="$id('org', 'legal_address')" label="Юр. адрес" type="textarea" rows="1" :value="$mine('legal_address', $company)" required/>
    </div>
    {{-- Банк — один на ИП и организацию: сменили вид между ними — счёт на месте. ИП без банка сервер не сохранит. --}}
    <div class="flex flex-col" data-reveal-target="pane" data-reveal-key="ip company" data-requisites-paste-target="group" @if ($kind === 'person') hidden @endif>
        <h3 class="list-head">Банк</h3>
        <div class="fields fields-stack">
            <x-ui.field :name="$n('account')" :id="$id('bank', 'account')" label="Расчётный счёт" :value="$party?->account" placeholder="20 цифр"/>
            <x-ui.field :name="$n('bank_name')" :id="$id('bank', 'bank_name')" label="Банк" :value="$party?->bank_name"/>
            <x-ui.field :name="$n('bik')" :id="$id('bank', 'bik')" label="БИК" :value="$party?->bik" placeholder="9 цифр"/>
            <x-ui.field :name="$n('corr_account')" :id="$id('bank', 'corr_account')" label="Корр. счёт" :value="$party?->corr_account" placeholder="20 цифр"/>
        </div>
    </div>
</div>
