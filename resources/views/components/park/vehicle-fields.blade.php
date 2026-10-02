{{-- Поля тождества ТС одной сеткой — дело и новая заявка (одни имена, правила — Park\VehicleFields).
     Без ТС — новая, значения из $values (разбор письма, old). Негабарита и повреждений тут нет.
     Такая ТС уже есть (номер убытка, VIN, госномер) — строками под полями сразу при вводе (twins_controller). --}}
@props(['vehicle' => null, 'values' => [], 'vendors', 'categories', 'brand' => null, 'model' => null, 'cols' => 'grid-cols-2 sm:grid-cols-3', 'candidate' => null])
@php
    $v = fn (string $k) => old($k, $vehicle?->{$k} ?? $values[$k] ?? null);
    $brand ??= $vehicle?->brand; $model ??= $vehicle?->model;
    $byValue = once(fn () => \App\Vendors\Vendor::where('rate_by_value', true)->pluck('id')->all());
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-3 '.$cols]) }} data-controller="twins" data-twins-url-value="/reference/twins" data-twins-except-value="{{ $vehicle?->id }}" data-twins-candidate-value="{{ $candidate }}" data-action="input->twins#changed">
    <x-ui.field name="ref" label="Номер убытка" :value="$v('ref')" autocapitalize="characters" autocorrect="off" spellcheck="false" copy/>
    <x-ui.field name="policy_no" label="№ полиса" :value="$v('policy_no')" copy/>
    <x-ui.field name="vendor_id" label="Вендор" :options="$vendors" placeholder="—" :value="$v('vendor_id')"/>
    <x-ui.combobox name="brand_id" label="Марка" url="/reference/brands" create="/reference/brands" :value="old('brand_id', $brand?->id)" :text="$brand?->name" resets="#cb-model_id"/>
    <x-ui.combobox name="model_id" label="Модель" url="/reference/models" create="/reference/models" depends="#f-brand_id" :value="old('model_id', $model?->id)" :text="$model?->name"/>
    <x-ui.field name="year" label="Год" :value="$v('year')"/>
    <x-ui.vin :value="$v('vin')" span="col-span-full sm:col-span-2"/>
    <x-ui.field name="plate" label="Госномер" :value="$v('plate')" autocapitalize="characters" copy/>
    <div class="col-span-full flex flex-col gap-2 empty:hidden" data-twins-target="box"></div>
    <x-ui.field name="color" label="Цвет" :value="$v('color')"/>
    <x-ui.field name="category" label="Категория" :options="$categories" placeholder="—" :value="$vehicle?->category?->value ?? $v('category')"/>
    {{-- Свои id: у формы звонка и эвакуации могут быть те же имена. --}}
    <x-ui.field name="contact_name" id="v-contact_name" label="Страхователь" :value="$v('contact_name')"/>
    <x-ui.field name="contact_phone" id="v-contact_phone" label="Телефон" type="tel" :value="$v('contact_phone')"/>
    {{-- Оценочная стоимость — только у вендора, чья ставка от неё (тумблер вендора «Ставка от оценочной стоимости»,
         договор Альфы); сменили вендора в форме — поле появляется или прячется сразу (by_vendor_controller). --}}
    <div data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array((int) $v('vendor_id'), $byValue, true)) hidden @endunless>
        <x-ui.field name="value" label="Оценочная стоимость, ₽" :value="$v('value')" autocomplete="off" data-controller="digits" data-action="input->digits#format"/>
    </div>
</div>
