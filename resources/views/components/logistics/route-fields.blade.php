{{-- «Куда везти» строками с галкой, как в Настройках (18.10.2026): к менеджеру сделки (если он есть), каждая наша парковка
     по названию (`yard:id`, `Point::read`), другой адрес (поле), а в окне назначения ещё «Пока неизвестно» (`unknown`). Одно на окно «Кто вывозит», окно
     «Куда везти» и «Забрал» (`x-logistics.pickup-fields`). Свой `reveal`: у окна «Кто вывозит» панели вида — свои.
     Город, потом место в нём — см. ниже. --}}
@props(['t' => null, 'offer' => null, 'prefix' => 'route', 'unknown' => false])
@php
    use App\Logistics\Choices;
    use App\Logistics\Point;
    $offer ??= $t?->saleOffer();
    $keeper = \App\Logistics\Transport::keeperLabel($offer);
    // Места по названию (18.10.2026: «„к нам“ — это куда?»): менеджер, каждая наша парковка, другой адрес; у задачи на стоянку
    // логиста — она первой строкой (исполнитель её видит и не теряет при «Забрал»).
    $points = array_filter([Point::Depot->value => $t?->destination === Point::Depot ? $t->depot?->label() : null])
        + Point::choices($keeper) + ($unknown ? ['' => 'Пока неизвестно'] : []);
    // Выбрано: что прислали, иначе точка задачи (у парковки — её строка); новой — менеджер сделки, если он есть, иначе
    // «пока неизвестно»; старое «к нам» — тоже «пока неизвестно».
    $current = match (true) {
        $t?->destination === Point::Yard && $t->to_yard_id => 'yard:'.$t->to_yard_id,
        $t?->destination !== null && $t->destination !== Point::Ours => $t->destination->value,
        $t?->reach !== null && $unknown, $t?->destination === Point::Ours && $unknown => '',
        default => $keeper ? Point::Keeper->value : ($unknown ? '' : array_key_first($points)),
    };
    $point = (string) old('destination', $current);
    // Сначала город, потом место в нём (22.10.2026, владелец: «как в нормальных приложениях»; логист не знала, какая из
    // парковок Москва, а какая Питер): поле «Город» (справочник ОКТМО), под ним наши парковки этого города, «Другой адрес»
    // (город встаёт в адрес сам — `Point::address`) и «Пока неизвестно». Город по умолчанию — парковки, куда везут, иначе
    // город машины. Выбрали город — дальность «Внутри региона | Между регионами» встаёт сама (`city_places_controller`).
    $yardCities = \App\Logistics\Choices::yardCities();
    $pickedYard = str_starts_with($point, 'yard:') ? (int) substr($point, 5) : null;
    $city = ($pickedYard && ($id = $yardCities[$pickedYard] ?? null) ? \App\Cars\Settlement::find($id) : null) ?? $offer?->settlement;
    $yards = array_filter($points, fn ($v) => str_starts_with((string) $v, 'yard:'), ARRAY_FILTER_USE_KEY);
    $head = array_diff_key(array_filter($points, fn ($v) => ! in_array((string) $v, ['other', ''], true), ARRAY_FILTER_USE_KEY), $yards);
    $tail = array_intersect_key($points, array_flip(['other', '']));
    $radio = fn ($value, $label, $attrs = '') => '<label class="row row-check" '.$attrs.'><span class="min-w-0 flex-1 '.($value === '' ? 'text-ink-muted' : '').'">'.e($label).'</span>'
        .'<span class="check"><input type="radio" name="destination" value="'.e($value).'" '.($point === (string) $value ? 'checked' : '').' data-action="reveal#pick"></span></label>';
@endphp
<div class="flex flex-col gap-3" data-controller="reveal city-places" data-action="change->city-places#sync" data-city-places-region-value="{{ \App\Logistics\Reach::area($t?->region_id) }}">
@if ($head)
    <div class="list">@foreach ($head as $value => $label){!! $radio((string) $value, $label) !!}@endforeach</div>
@endif
<div class="fields" data-action="combobox:chosen->city-places#chosen">
    <x-ui.combobox name="to_city_id" :id="$prefix.'-city'" text-name="to_city" label="Город" url="/reference/settlements" :value="$city?->id" :text="$city?->title()" placeholder="Начните вводить город"/>
</div>
<div class="list">
    @foreach ($yards as $value => $label)
        {!! $radio((string) $value, $label, 'data-city-places-target="place" data-city="'.($yardCities[(int) substr($value, 5)] ?? '').'"') !!}
    @endforeach
    @foreach ($tail as $value => $label){!! $radio((string) $value, $label) !!}@endforeach
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
    <x-ui.field name="to_address" :id="$prefix.'-address'" label="Адрес" type="textarea" :value="old('to_address', $t?->to_address)"/>
</div>
@error('destination')<p class="field-error">{{ $message }}</p>@enderror
</div>
