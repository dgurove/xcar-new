{{-- Поля одной группы редактора поставщика строками `.fields` (08.11.2026): марка и модель — справочниками, перечисления —
     выбором, объём — «1,6», числа — разрядами. Значение — из формы с ошибкой, иначе у предложения (`$raw` редактора). --}}
@php
    use App\Cars\CarFields;
    use App\Offers\Offer;
    use App\Support\Liters;
@endphp
@foreach ($columns as $c)
    @php $name = CarFields::name(Offer::class, $c); @endphp
    @switch (CarFields::kind($c))
        @case ('brand')
            <x-cars.brand-model :brand-id="$raw('brand_id')" :brand-text="$offer->car?->brand?->name" :model-id="$raw('model_id')" :model-text="$offer->car?->model?->name" required/>
            @break
        @case ('model')
            @break
        @case ('enum')
            <x-ui.field :name="$name" :label="CarFields::label($c)" :options="CarFields::enum($c)::options()" placeholder="—" :value="$raw($c)"/>
            @break
        @case ('liters')
            <x-ui.field :name="$name" :label="CarFields::label($c)" placeholder="1,6" :value="Liters::format($raw($c))"/>
            @break
        @case ('vin')
            <x-ui.field :name="$name" label="VIN" :value="$raw($c)"/>
            @break
        @default
            <x-ui.field :name="$name" :label="CarFields::label($c)" :value="$raw($c)" :digits="CarFields::kind($c) === 'digits'"/>
    @endswitch
@endforeach
