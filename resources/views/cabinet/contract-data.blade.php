{{-- Данные для договора у покупателя (19.10.2026): скан двух страниц паспорта (распознаётся сам и дописывает поля) и
     поля паспорта или организации — те же куски, что в шторке менеджера «Данные для договора». --}}
<x-ui.cabinet title="Данные для договора" :back="['Кабинет', '/account']">
    @if ($party->isPerson())<x-billing.passport-scan :party="$party" form="contract-data-form"/>@endif
    <form method="post" action="/account/contract" id="contract-data-form" class="box form-dense flex flex-col gap-4" data-controller="save-bar" data-save-bar-dirty-value="{{ $errors->any() ? 'true' : 'false' }}">
        @csrf @method('put')
        <x-billing.buyer-fields :party="$party" key="contract-data"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
        <x-ui.save-bar page/>
    </form>
</x-ui.cabinet>
