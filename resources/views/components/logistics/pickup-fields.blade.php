{{-- Поля «Забрал» — одни в карточке задачи и на странице исполнителя по ссылке (18.10.2026): кадры приёма, сколько ключей,
     куда везёт (у заявки логиста — её адрес), комментарий. `url` — куда грузить кадры. --}}
@props(['t', 'url' => null])
@php
    use App\Logistics\Choices;
    use App\Logistics\Point;
    $id = $t->id;
    $order = $t->isOrder();
@endphp
@php
    $keysNow = old('keys');
    $points = array_filter([
        Point::Keeper->value => \App\Logistics\Transport::keeperLabel($t->saleOffer()),
        Point::Ours->value => Point::Ours->label(),
        Point::Yard->value => Point::Yard->label(),
        Point::Other->value => Point::Other->label(),
    ]);
    $point = old('destination', $t->destination?->value ?? array_key_first($points));
@endphp
<x-logistics.photos :t="$t" at="pickup" edit class="!mt-0" :url="$url"/>
<div>
    <h3 class="list-head">Сколько ключей</h3>
    <x-ui.segment name="keys" :options="[0 => 'Нет', 1 => '1', 2 => '2', 3 => '3+']" :value="$keysNow"/>
</div>
@if ($order)
    {{-- Заявка логиста: куда — адрес заявки, точек XCar у неё нет. --}}
    <input type="hidden" name="destination" value="other"><input type="hidden" name="to_address" value="{{ $t->to_address }}">
@else
{{-- Куда — строками с галкой, как в Настройках: сегментом из четырёх «Менеджеру Иван П.» и «Другой адрес»
     ломались в две строки на телефоне. --}}
<div>
    <h3 class="list-head">Куда везёте</h3>
    <div class="list">
        @foreach ($points as $value => $label)
            <label class="row row-check">
                <span class="min-w-0 flex-1">{{ $label }}</span>
                <span class="check"><input type="radio" name="destination" value="{{ $value }}" @checked((string) $point === (string) $value) data-action="reveal#pick"></span>
            </label>
        @endforeach
    </div>
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="yard" @if ($point !== 'yard') hidden @endif>
    <x-ui.field name="to_yard_id" :id="'pickup-yard-'.$id" label="Парковка" :options="Choices::yards()" placeholder="Выберите" :value="old('to_yard_id', $t->to_yard_id)"/>
</div>
<div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
    <x-ui.field name="to_address" :id="'pickup-address-'.$id" label="Адрес" type="textarea" :value="old('to_address', $t->to_address)"/>
</div>
@endif
<div class="fields"><x-ui.field name="pickup_note" :id="'pickup-note-'.$id" label="Комментарий" type="textarea" :value="old('pickup_note', $t->pickup_note)"/></div>
@error('photos')<p class="field-error">{{ $message }}</p>@enderror
