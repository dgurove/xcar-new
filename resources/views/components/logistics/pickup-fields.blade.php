{{-- Поля «Забрал» — одни в карточке задачи и на странице исполнителя по ссылке (18.10.2026): кадры приёма, сколько ключей,
     куда везёт (у заявки логиста — её адрес), комментарий. `url` — куда грузить кадры. --}}
@props(['t', 'url' => null])
@php
    $id = $t->id;
    $order = $t->isOrder();
@endphp
@php $keysNow = old('keys'); @endphp
<x-logistics.photos :t="$t" at="pickup" edit class="!mt-0" :url="$url"/>
<div>
    <h3 class="list-head">Сколько ключей</h3>
    <x-ui.segment name="keys" :options="[0 => 'Нет', 1 => '1', 2 => '2', 3 => '3+']" :value="$keysNow"/>
</div>
@if ($order)
    {{-- Заявка логиста: куда — адрес заявки, точек XCar у неё нет. --}}
    <input type="hidden" name="destination" value="other"><input type="hidden" name="to_address" value="{{ $t->to_address }}">
@else
{{-- Куда — строками с галкой (`x-logistics.route-fields`), выбрано то, что указали при назначении. --}}
<div>
    <h3 class="list-head">Куда везёте</h3>
    <x-logistics.route-fields :t="$t" :prefix="'pickup-'.$id"/>
</div>
@endif
<div class="fields"><x-ui.field name="pickup_note" :id="'pickup-note-'.$id" label="Комментарий" type="textarea" :value="old('pickup_note', $t->pickup_note)"/></div>
@error('photos')<p class="field-error">{{ $message }}</p>@enderror
