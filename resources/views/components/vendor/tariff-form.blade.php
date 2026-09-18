{{-- Одна строка прайса формой; без $t — новая. Ступень по суткам и километры в фиксе — только там, где они есть. --}}
@php use App\Vendors\TariffService; $new = ! $t; $key = $t?->id ?? 'new'; @endphp
<form method="post" action="{{ $t ? '/settings/tariffs/'.$t->id : '/settings/tariffs' }}" class="box-nested flex flex-col gap-3" data-controller="tariff-form">
    @csrf @if ($t) @method('put') @endif
    <input type="hidden" name="vendor_id" value="{{ $vendorId }}">
    <input type="hidden" name="yard_id" value="{{ $yardId }}">
    <input type="hidden" name="category" value="{{ $catValue }}">
    <input type="hidden" name="_sheet" value="{{ $catValue ?? 'any' }}">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-ui.field name="service" label="Услуга" :options="TariffService::options()" :value="$t?->service->value ?? 'storage'" :id="'svc-'.$key" data-tariff-form-target="service" data-action="change->tariff-form#sync" span="col-span-2"/>
        <x-ui.field name="price" label="Цена" :value="$t?->price !== null ? rtrim(rtrim(number_format($t->price, 2, '.', ''), '0'), '.') : null" :id="'price-'.$key" required/>
        <x-ui.field name="from_day" label="С суток" :value="$t?->from_day ?? 1" :id="'from-'.$key" inputmode="numeric" data-tariff-form-target="tier"/>
        <x-ui.field name="km_included" label="Км в фиксе" :value="$t?->km_included" :id="'km-'.$key" inputmode="numeric" data-tariff-form-target="km"/>
        <x-ui.field name="valid_from" label="С даты" type="date" :value="$t?->valid_from?->toDateString() ?? now()->toDateString()" :id="'vf-'.$key"/>
        <x-ui.field name="valid_to" label="По дату" type="date" :value="$t?->valid_to?->toDateString()" :id="'vt-'.$key"/>
        <x-ui.field name="note" label="Заметка" :value="$t?->note" :id="'note-'.$key" span="col-span-2"/>
        <x-ui.check name="vat" :checked="$t?->vat ?? false" class="col-span-2 self-end">С НДС</x-ui.check>
    </div>
    <div class="flex gap-2">
        <x-ui.button size="sm" :variant="$new ? 'primary' : 'secondary'">{{ $new ? 'Добавить' : 'Сохранить' }}</x-ui.button>
        @if ($t)<x-ui.button size="sm" variant="ghost" formaction="/settings/tariffs/{{ $t->id }}" formmethod="post" name="_method" value="delete" data-turbo-confirm="Убрать строку прайса?">Убрать</x-ui.button>@endif
    </div>
</form>
