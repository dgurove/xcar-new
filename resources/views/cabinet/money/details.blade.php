{{-- Реквизиты менеджера для счетов и выплат: вид, поля по виду, банк или карта. --}}
<x-ui.cabinet title="Реквизиты" :back="['Оплаты', '/account/money']">
    {{-- Физлицо, покупающее «Я сам», — со сканом паспорта: без него счёт ПРАЙМ и ДКП не встают (11.10.2026). --}}
    @if ($party?->exists && $party->isPerson())<x-billing.passport-scan :party="$party" form="details-form" prefix=""/>@endif
    <form method="post" action="/account/money/details" id="details-form" class="box form-dense flex flex-col gap-4" data-controller="save-bar" data-save-bar-dirty-value="{{ $errors->any() || ! $party?->exists ? 'true' : 'false' }}">
        @csrf @method('put')
        <x-billing.party-fields :party="$party" :staff="false"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
        <x-ui.save-bar page/>
    </form>
</x-ui.cabinet>
