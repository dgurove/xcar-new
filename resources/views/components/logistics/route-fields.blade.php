{{-- «Куда везти» строками с галкой, как в Настройках (18.10.2026): к менеджеру сделки (если он есть), каждая наша парковка
     по названию (`yard:id`, `Point::read`), другой адрес (поле), а в окне назначения ещё «Пока неизвестно» (`unknown`). Одно на окно «Кто вывозит», окно
     «Куда везти» и «Забрал» (`x-logistics.pickup-fields`). Свой `reveal`: у окна «Кто вывозит» панели вида — свои.
     Парковки — по дальности (22.10.2026, владелец: «внутри региона» у машины из Тольятти стояли парковки Москвы и Питера):
     «Внутри региона» — только парковки зоны задачи (`Reach::area`), «Между регионами» и регион неизвестен — все
     (`region_yards_controller` по сегменту `reach` той же формы; выбранная строка не прячется). --}}
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
    $areas = \App\Logistics\Choices::yardAreas();
    $area = \App\Logistics\Reach::area($t?->region_id);
@endphp
<div class="flex flex-col gap-3" data-controller="reveal region-yards" data-region-yards-area-value="{{ $area }}" data-region-yards-reach-value="{{ $t?->reach?->value }}">
<div class="list">
    @foreach ($points as $value => $label)
        @php $yardArea = str_starts_with((string) $value, 'yard:') ? ($areas[(int) substr($value, 5)] ?? null) : null; @endphp
        <label class="row row-check" @if ($yardArea) data-region-yards-target="row" data-area="{{ $yardArea }}" @endif>
            <span class="min-w-0 flex-1 {{ $value === '' ? 'text-ink-muted' : '' }}">{{ $label }}</span>
            <span class="check"><input type="radio" name="destination" value="{{ $value }}" @checked($point === (string) $value) data-action="reveal#pick"></span>
        </label>
    @endforeach
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
    <x-ui.field name="to_address" :id="$prefix.'-address'" label="Адрес" type="textarea" :value="old('to_address', $t?->to_address)"/>
</div>
@error('destination')<p class="field-error">{{ $message }}</p>@enderror
</div>
