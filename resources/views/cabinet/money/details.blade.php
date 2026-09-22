{{-- Реквизиты менеджера для счетов и выплат: вид, поля по виду, банк или карта. --}}
<x-ui.cabinet title="Реквизиты" :back="['Деньги', '/account/money']">
    <form method="post" action="/account/money/details" class="box form-dense flex flex-col gap-4">
        @csrf @method('put')
        <x-billing.party-fields :party="$party" :staff="false"/>
        @if ($errors->any())<p class="field-error">{{ $errors->first() }}</p>@endif
        <x-ui.button class="w-full sm:w-fit">Сохранить</x-ui.button>
    </form>
</x-ui.cabinet>
