{{-- Поля контрагента по виду: юрлицо, ИП, самозанятый, физлицо — вид выбирается чипами, лишние поля прячутся
     (reveal_controller выключает их, чтобы не ушли в форму). Банк общий, карта — где счёта может не быть.
     staff — служебные поля (руководитель, назначение платежа, заметки), менеджеру в кабинете они не нужны.
     vat — «НДС сверху»: у плательщика счета ПРАЙМ (CRM) и ИП (парковка) прибавляют НДС к строкам, а не выделяют из суммы.
     Паспорт физлица — тем же куском, что у договора (`x-billing.passport-fields`, 18.11.2026, владелец: «в CRM и в xcar форму
     одинаковой»). payouts — мы этому человеку платим (исполнители вывоза и парковки): «Банк» у физлица и самозанятого; иначе
     (менеджеру на сайте и в CRM — «зачем мы покупателю банк прописываем?») банк только у ИП и юрлица — его печатает ДКП. --}}
@props(['party' => null, 'staff' => true, 'vat' => false, 'payouts' => true])
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
    {{-- «ФИО» у человека, «Название» у юрлица: одно поле `name`, подпись — по виду (18.11.2026, владелец: «не „Название или ФИО“»). --}}
    <div data-reveal-target="pane" data-reveal-key="ip npd person" @if ($kind === 'company') hidden @endif><x-ui.field name="name" id="name-person" label="ФИО" :value="$party?->name" required/></div>
    <div data-reveal-target="pane" data-reveal-key="company" @if ($kind !== 'company') hidden @endif><x-ui.field name="name" id="name-company" label="Название" :value="$party?->name" required/></div>
    <div class="{{ $g }}" data-reveal-target="pane" data-reveal-key="company">
        <x-ui.field name="inn" id="inn-company" label="ИНН" :value="$party?->inn"/>
        <x-ui.field name="kpp" id="kpp-company" label="КПП" :value="$party?->kpp"/>
        <x-ui.field name="ogrn" id="ogrn-company" label="ОГРН" :value="$party?->ogrn"/>
        <x-ui.field name="legal_address" id="legal_address-company" label="Юридический адрес" :value="$party?->legal_address"/>
        @if ($staff)
            <x-ui.field name="director" id="director-company" label="Руководитель" :value="$party?->director"/>
            <x-ui.field name="director_basis" id="director_basis-company" label="Действует на основании" :value="$party?->director_basis"/>
            {{-- Для договоров (ДКП, 19.10.2026): полное наименование, подписант полностью и «в лице …» родительным падежом. --}}
            <x-ui.field name="full_name" id="full_name-company" label="Полное наименование" :value="$party?->full_name" span="col-span-2"/>
            <x-ui.field name="director_full" id="director_full-company" label="Руководитель, ФИО полностью" :value="$party?->director_full"/>
            <x-ui.field name="signed_by" id="signed_by-company" label="В лице" :value="$party?->signed_by" placeholder="Генерального директора Иванова Ивана Ивановича"/>
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
    <div data-reveal-target="pane" data-reveal-key="person" @if ($kind !== 'person') hidden @endif>
        <x-billing.passport-fields :party="$party" key="party" :fio="false" :required="false" bare/>
    </div>
    <div class="flex flex-col gap-3" data-reveal-target="pane" data-reveal-key="{{ $payouts ? 'company ip npd person' : 'company ip' }}" @if (! $payouts && ! in_array($kind, ['company', 'ip'], true)) hidden @endif>
    <x-ui.section-title level="h3" class="!text-lg">Банк</x-ui.section-title>
    <div class="{{ $g }}">
        <x-ui.field name="bank_name" label="Банк" :value="$party?->bank_name" span="col-span-2"/>
        <x-ui.field name="bik" label="БИК" :value="$party?->bik"/>
        <x-ui.field name="account" label="Расчётный счёт" :value="$party?->account"/>
        <x-ui.field name="corr_account" label="Корр. счёт" :value="$party?->corr_account"/>
        <x-ui.field name="card" label="Номер карты" :value="$party?->card"/>
        @if ($staff)<x-ui.field name="payment_purpose" label="Назначение платежа" :value="$party?->payment_purpose" span="col-span-2"/>@endif
    </div>
    </div>
    <div class="{{ $g }}">
        <x-ui.field name="phone" label="Телефон" type="tel" :value="$party?->phone"/>
        <x-ui.field name="email" label="Почта" type="email" :value="$party?->email"/>
    </div>
    @if ($vat)<x-ui.check name="vat_on_top" :checked="$party?->vat_on_top">НДС сверху</x-ui.check>@endif
    @if ($staff)<x-ui.field name="notes" label="Заметки" type="textarea" :value="$party?->notes"/>@endif
</div>
