{{-- Поля ТС одной сеткой — карточка ТС и компактная карточка на заявке (одни имена полей, одна валидация в
     VehicleController::update и RequestController::store). Без ТС — новая, значения из $values (разбор письма, old). --}}
@props(['vehicle' => null, 'values' => [], 'vendors', 'categories', 'brand' => null, 'model' => null, 'oversize' => true, 'cols' => 'grid-cols-2 lg:grid-cols-3'])
@php
    $v = fn (string $k) => old($k, $vehicle?->{$k} ?? $values[$k] ?? null);
    $brand ??= $vehicle?->brand; $model ??= $vehicle?->model;
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-3 '.$cols]) }}>
    <x-ui.field name="ref" label="Номер убытка" :value="$v('ref')" span="col-span-2 lg:col-span-1"/>
    <x-ui.field name="vendor_id" label="Заказчик" :options="$vendors" placeholder="—" :value="$v('vendor_id')"/>
    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="old('brand_id', $brand?->id)" :text="$brand?->name" resets="#cb-model_id"/>
    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="old('model_id', $model?->id)" :text="$model?->name"/>
    <x-ui.field name="year" label="Год" inputmode="numeric" :value="$v('year')"/>
    <x-ui.field name="plate" label="Госномер" :value="$v('plate')" autocapitalize="characters"/>
    <x-ui.vin :value="$v('vin')" span="col-span-2 lg:col-span-1"/>
    <x-ui.field name="color" label="Цвет" :value="$v('color')"/>
    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—" :value="$vehicle?->category?->value ?? $v('category')"/>
    <x-ui.field name="value" label="Оценка, ₽" :value="$v('value')" inputmode="numeric"/>
    {{-- Свои id: на странице заявки те же поля есть у формы звонка и эвакуации. --}}
    <x-ui.field name="contact_name" id="v-contact_name" label="Страхователь" :value="$v('contact_name')"/>
    <x-ui.field name="contact_phone" id="v-contact_phone" label="Телефон" type="tel" :value="$v('contact_phone')"/>
    @if ($oversize)<input type="hidden" name="oversize_form" value="1"><x-ui.check name="oversize" :checked="(bool) old('oversize', $vehicle?->oversize)" class="self-end">Негабарит</x-ui.check>@endif
</div>
