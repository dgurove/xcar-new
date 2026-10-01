{{-- Поля тождества ТС одной сеткой — дело и новая заявка (одни имена, правила — Park\VehicleFields).
     Без ТС — новая, значения из $values (разбор письма, old). Негабарита и повреждений тут нет.
     Такая ТС уже есть (номер убытка, VIN, госномер) — строками под полями сразу при вводе (twins_controller). --}}
@props(['vehicle' => null, 'values' => [], 'vendors', 'categories', 'brand' => null, 'model' => null, 'cols' => 'grid-cols-2 sm:grid-cols-3', 'candidate' => null, 'differences' => []])
@php
    $v = fn (string $k) => old($k, $vehicle?->{$k} ?? $values[$k] ?? null);
    $brand ??= $vehicle?->brand; $model ??= $vehicle?->model;
@endphp
<div {{ $attributes->merge(['class' => 'grid gap-3 '.$cols]) }} data-controller="twins" data-twins-url-value="/reference/twins" data-twins-except-value="{{ $vehicle?->id }}" data-twins-candidate-value="{{ $candidate }}" data-action="input->twins#changed">
    {{-- Карточка и документы дела расходятся (FillFromDocs::differences): чип — взять значение документа. Кнопка
         отправляет форму вокруг на свой адрес — вложенной формы быть не может. --}}
    @if ($vehicle && $differences)
        <div class="col-span-full flex flex-wrap gap-1.5">
            @foreach ($differences as $field => $d)
                <button type="submit" class="chip" formaction="/cars/{{ $vehicle->id }}/take" formmethod="post" formnovalidate name="take" value="{{ $field }}|{{ $d['value'] }}"
                    data-turbo-confirm="{{ \App\Support\FieldLabels::list([$field === 'model' ? 'model_id' : $field]) }}: «{{ $d['value'] }}» из документа ({{ implode(', ', $d['sources']) }})?"
                    data-turbo-confirm-label="Взять"><span class="text-ink-dim">в документе</span> <span class="{{ in_array($field, ['vin', 'year', 'value'], true) ? 'nums' : '' }}">{{ $d['value'] }}</span></button>
            @endforeach
        </div>
    @endif
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
    <x-ui.field name="value" label="Заявленная стоимость, ₽" :value="$v('value')" autocomplete="off" data-controller="digits" data-action="input->digits#format"/>
</div>
