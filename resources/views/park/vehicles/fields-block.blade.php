{{-- Машина в деле — те же блоки, что в CRM (`x-car.blocks`, 19.10.2026, владелец: «ТС одна сущность и в парковке и в CRM»):
     «Транспортное средство», «ПТС и СТС», «Страхователь» и своё парковки — «Хранение» (оценочная и своя ставка). Правило
     одно с CRM: машина заведена — блоки текстом с «Изменить / Готово», пустые с «Добавить» (владелец: «на парковке всё
     ещё не сворачиваются»; было — полями, пока ТС ждём); полями — только новая заявка и разбор письма. Нет марки или
     модели — «Транспортное средство» сразу полями; блок с ошибкой раскрывается сам. Над полями машины — расхождения
     «в карточке → в документе» с «Взять». --}}
@php
    $differences ??= [];
    $fold = true;
    $hints = $vehicle->brand_id && $vehicle->model_id ? [] : ['brand_id', 'model_id'];
@endphp
<x-car.blocks :lane="$vehicle" :blocks="\App\Park\VehicleFields::BLOCKS" :fold="$fold" :hints="$hints" :required="['brand_id']">
    <x-slot:top>
        <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
    </x-slot:top>
</x-car.blocks>
@include('park.vehicles.storage-block', ['fold' => $fold])
<x-ui.save-bar page/>
