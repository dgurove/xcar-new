{{-- «Куда везти» строками с галкой, как в Настройках (18.10.2026): к менеджеру сделки (если он есть), к нам, на парковку
     (выбор), другой адрес (поле), а в окне назначения ещё «Пока неизвестно» (`unknown`). Одно на окно «Кто вывозит», окно
     «Куда везти» и «Забрал» (`x-logistics.pickup-fields`). Свой `reveal`: у окна «Кто вывозит» панели вида — свои. --}}
@props(['t' => null, 'offer' => null, 'prefix' => 'route', 'unknown' => false])
@php
    use App\Logistics\Choices;
    use App\Logistics\Point;
    $offer ??= $t?->saleOffer();
    $keeper = \App\Logistics\Transport::keeperLabel($offer);
    // Задача везёт на стоянку логиста — она первой строкой (исполнитель её видит и не теряет при «Забрал»).
    $points = array_filter([
        Point::Depot->value => $t?->destination === Point::Depot ? $t->depot?->label() : null,
        Point::Keeper->value => $keeper,
        Point::Ours->value => Point::Ours->label(),
        Point::Yard->value => Point::Yard->label(),
        Point::Other->value => Point::Other->label(),
    ]) + ($unknown ? ['' => 'Пока неизвестно'] : []);
    // Выбрано: что прислали, иначе точка задачи; новой — менеджер сделки, если он есть, иначе «пока неизвестно».
    $chosen = $t?->destination?->value ?? ($t?->reach !== null && $unknown ? '' : ($keeper ? Point::Keeper->value : ($unknown ? '' : array_key_first($points))));
    $point = (string) old('destination', $chosen);
@endphp
<div class="flex flex-col gap-3" data-controller="reveal">
<div class="list">
    @foreach ($points as $value => $label)
        <label class="row row-check">
            <span class="min-w-0 flex-1 {{ $value === '' ? 'text-ink-muted' : '' }}">{{ $label }}</span>
            <span class="check"><input type="radio" name="destination" value="{{ $value }}" @checked($point === (string) $value) data-action="reveal#pick"></span>
        </label>
    @endforeach
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="yard" @if ($point !== 'yard') hidden @endif>
    <x-ui.field name="to_yard_id" :id="$prefix.'-yard'" label="Парковка" :options="Choices::yards()" placeholder="Выберите" :value="old('to_yard_id', $t?->to_yard_id)"/>
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
    <x-ui.field name="to_address" :id="$prefix.'-address'" label="Адрес" type="textarea" :value="old('to_address', $t?->to_address)"/>
</div>
@error('destination')<p class="field-error">{{ $message }}</p>@enderror
</div>
