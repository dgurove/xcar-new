@php use App\Billing\PartyKind; $g = 'grid grid-cols-2 gap-3'; @endphp
<form method="post" action="{{ $party ? '/money/parties/'.$party->id : '/money/parties' }}" class="flex flex-col gap-4">
    @csrf @if ($party) @method('put') @endif
    <div class="{{ $g }}">
        <x-ui.field name="kind" label="Кто" :options="PartyKind::options()" :value="$party?->kind->value ?? 'company'"/>
        <x-ui.field name="name" label="Название или ФИО" :value="$party?->name" required/>
        <x-ui.field name="inn" label="ИНН" :value="$party?->inn" inputmode="numeric"/>
        <x-ui.field name="kpp" label="КПП" :value="$party?->kpp" inputmode="numeric"/>
        <x-ui.field name="ogrn" label="ОГРН" :value="$party?->ogrn" inputmode="numeric"/>
        <x-ui.field name="legal_address" label="Адрес" :value="$party?->legal_address"/>
        <x-ui.field name="director" label="Руководитель" :value="$party?->director"/>
        <x-ui.field name="director_basis" label="Действует на основании" :value="$party?->director_basis"/>
    </div>
    <x-ui.section-title level="h3" class="!text-lg">Банк</x-ui.section-title>
    <div class="{{ $g }}">
        <x-ui.field name="bank_name" label="Банк" :value="$party?->bank_name" span="col-span-2"/>
        <x-ui.field name="bik" label="БИК" :value="$party?->bik" inputmode="numeric"/>
        <x-ui.field name="account" label="Расчётный счёт" :value="$party?->account" inputmode="numeric"/>
        <x-ui.field name="corr_account" label="Корр. счёт" :value="$party?->corr_account" inputmode="numeric"/>
        <x-ui.field name="payment_purpose" label="Назначение платежа" :value="$party?->payment_purpose"/>
    </div>
    <x-ui.section-title level="h3" class="!text-lg">Физлицо</x-ui.section-title>
    <div class="{{ $g }}">
        <x-ui.field name="passport" label="Паспорт, серия и номер" :value="$party?->passport"/>
        <x-ui.field name="passport_issued" label="Кем и когда выдан" :value="$party?->passport_issued"/>
        <x-ui.field name="reg_address" label="Адрес регистрации" :value="$party?->reg_address"/>
        <x-ui.field name="birth_at" label="Дата рождения" type="date" :value="$party?->birth_at?->toDateString()"/>
        <x-ui.field name="phone" label="Телефон" type="tel" :value="$party?->phone"/>
        <x-ui.field name="email" label="Почта" type="email" :value="$party?->email"/>
    </div>
    <x-ui.field name="notes" label="Заметки" type="textarea" :value="$party?->notes"/>
    <x-ui.button block>Сохранить</x-ui.button>
</form>
@if ($party && ! $party->is_self && ! $party->invoices_count)
    <form method="post" action="/money/parties/{{ $party->id }}" class="mt-2" data-turbo-confirm="Убрать контрагента «{{ $party->name }}»?">@csrf @method('delete')<x-ui.button variant="ghost" block>Убрать</x-ui.button></form>
@endif
