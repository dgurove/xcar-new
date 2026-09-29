{{-- Поля «Транспортное средство» — одни на редактор и окошко строки. $grid — сетка колонок. Другое предложение с тем же
     VIN — строкой под полем сразу при вводе (twins_controller). --}}
@php use App\Cars\{Body, Transmission, Drive, Fuel}; @endphp
<div class="{{ $grid }}" data-controller="twins" data-twins-url-value="/offers/twins" data-twins-except-value="{{ $offer->id }}" data-action="input->twins#changed">
    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="$offer->brand_id" :text="$offer->brand?->name" resets="#cb-model_id"/>
    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="$offer->model_id" :text="$offer->model?->name"/>
    <x-ui.vin :value="$offer->vin" span="col-span-2 lg:col-span-1">
        <x-slot:after-label><x-ui.eye-check name="show_vin" :checked="$offer->show_vin"/></x-slot:after-label>
    </x-ui.vin>
    <div class="col-span-full flex flex-col gap-2 empty:hidden" data-twins-target="box"></div>
    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$offer->year"/>
    <x-ui.field name="mileage" data-controller="digits" data-action="input->digits#format" label="Пробег, км" inputmode="numeric" :value="$offer->mileage"/>
    <x-ui.field name="color" label="Цвет" :value="$offer->color"/>
    <x-ui.field name="body" label="Кузов" :options="Body::options()" placeholder="—" :value="$offer->body?->value"/>
    <x-ui.field name="transmission" label="Коробка" :options="Transmission::options()" placeholder="—" :value="$offer->transmission?->value"/>
    <x-ui.field name="drive" label="Привод" :options="Drive::options()" placeholder="—" :value="$offer->drive?->value"/>
    <x-ui.field name="fuel" label="Топливо" :options="Fuel::options()" placeholder="—" :value="$offer->fuel?->value"/>
    <x-ui.field name="engine_volume" label="Объём, л" inputmode="decimal" placeholder="1,6" :value="\App\Support\Liters::format($offer->engine_volume)"/>
    <x-ui.field name="engine_power" label="Мощность, л. с." inputmode="numeric" :value="$offer->engine_power"/>
    <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" :value="$offer->settlement_id" :text="$offer->settlement?->name"/>
    <x-ui.field name="inspection_address" label="Адрес осмотра" :value="$offer->inspection_address" span="col-span-2">
        <x-slot:after-label><x-ui.eye-check name="show_address" :checked="$offer->show_address"/></x-slot:after-label>
    </x-ui.field>
</div>
