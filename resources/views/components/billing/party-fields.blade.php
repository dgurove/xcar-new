{{-- Поля контрагента по виду: юрлицо, ИП, самозанятый, физлицо — вид выбирается чипами, лишние поля прячутся
     (reveal_controller выключает их, чтобы не ушли в форму). Банк общий, карта — где счёта может не быть.
     staff — служебные поля (руководитель, назначение платежа, заметки), менеджеру в кабинете они не нужны.
     vat — «НДС сверху»: у плательщика счета ПРАЙМ (CRM) и ИП (парковка) прибавляют НДС к строкам, а не выделяют из суммы. --}}
@props(['party' => null, 'staff' => true, 'vat' => false])
@php
    use App\Billing\PartyKind;
    $g = 'grid grid-cols-2 gap-3';
    $kind = old('kind', $party?->kind?->value ?? 'company');
@endphp
<div class="flex flex-col gap-4" data-controller="reveal">
    <div class="flex flex-wrap gap-2">
        @foreach (PartyKind::cases() as $k)
            <label class="choice"><input type="radio" name="kind" value="{{ $k->value }}" @checked($kind === $k->value) data-action="reveal#pick"><span>{{ $k->label() }}</span></label>
        @endforeach
    </div>
    <x-ui.field name="name" label="Название или ФИО" :value="$party?->name" required/>
    <div class="{{ $g }}" data-reveal-target="pane" data-reveal-key="company">
        <x-ui.field name="inn" id="inn-company" label="ИНН" :value="$party?->inn"/>
        <x-ui.field name="kpp" id="kpp-company" label="КПП" :value="$party?->kpp"/>
        <x-ui.field name="ogrn" id="ogrn-company" label="ОГРН" :value="$party?->ogrn"/>
        <x-ui.field name="legal_address" id="legal_address-company" label="Юридический адрес" :value="$party?->legal_address"/>
        @if ($staff)
            <x-ui.field name="director" id="director-company" label="Руководитель" :value="$party?->director"/>
            <x-ui.field name="director_basis" id="director_basis-company" label="Действует на основании" :value="$party?->director_basis"/>
        @endif
    </div>
    <div class="{{ $g }}" data-reveal-target="pane" data-reveal-key="ip">
        <x-ui.field name="inn" id="inn-ip" label="ИНН" :value="$party?->inn"/>
        <x-ui.field name="ogrn" id="ogrn-ip" label="ОГРНИП" :value="$party?->ogrn"/>
        <x-ui.field name="legal_address" id="legal_address-ip" label="Адрес регистрации" :value="$party?->legal_address" span="col-span-2"/>
    </div>
    <div class="{{ $g }}" data-reveal-target="pane" data-reveal-key="npd">
        <x-ui.field name="inn" id="inn-npd" label="ИНН" :value="$party?->inn"/>
        <x-ui.field name="reg_address" id="reg_address-npd" label="Адрес регистрации" :value="$party?->reg_address"/>
    </div>
    <div class="{{ $g }}" data-reveal-target="pane" data-reveal-key="person">
        <x-ui.field name="passport" id="passport-person" label="Паспорт, серия и номер" :value="$party?->passport"/>
        <x-ui.field name="passport_issued" id="passport_issued-person" label="Кем и когда выдан" :value="$party?->passport_issued"/>
        <x-ui.field name="reg_address" id="reg_address-person" label="Адрес регистрации" :value="$party?->reg_address"/>
        <x-ui.field name="birth_at" id="birth_at-person" label="Дата рождения" type="date" :value="$party?->birth_at?->toDateString()"/>
    </div>
    <x-ui.section-title level="h3" class="!text-lg">Банк</x-ui.section-title>
    <div class="{{ $g }}">
        <x-ui.field name="bank_name" label="Банк" :value="$party?->bank_name" span="col-span-2"/>
        <x-ui.field name="bik" label="БИК" :value="$party?->bik"/>
        <x-ui.field name="account" label="Расчётный счёт" :value="$party?->account"/>
        <x-ui.field name="corr_account" label="Корр. счёт" :value="$party?->corr_account"/>
        <x-ui.field name="card" label="Номер карты" :value="$party?->card"/>
        @if ($staff)<x-ui.field name="payment_purpose" label="Назначение платежа" :value="$party?->payment_purpose" span="col-span-2"/>@endif
    </div>
    <div class="{{ $g }}">
        <x-ui.field name="phone" label="Телефон" type="tel" :value="$party?->phone"/>
        <x-ui.field name="email" label="Почта" type="email" :value="$party?->email"/>
    </div>
    @if ($vat)<x-ui.check name="vat_on_top" :checked="$party?->vat_on_top">НДС сверху</x-ui.check>@endif
    @if ($staff)<x-ui.field name="notes" label="Заметки" type="textarea" :value="$party?->notes"/>@endif
</div>
