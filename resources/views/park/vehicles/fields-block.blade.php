{{-- Машина в деле — те же блоки, что в CRM (`x-car.blocks`, 19.10.2026, владелец: «ТС одна сущность и в парковке и в CRM»):
     «Транспортное средство», «ПТС и СТС», «Страхователь» и своё парковки — «Хранение» (оценочная и своя ставка). Пока ТС
     ждём — полями (данные из письма сверяют); после приёма — текстом с «Изменить / Готово», как в CRM. Над полями машины —
     расхождения «в карточке → в документе» с «Взять»; блок с ошибкой раскрывается сам. --}}
@php
    $differences ??= [];
    $fold = $vehicleOpen ? null : true;
@endphp
<x-car.blocks :lane="$vehicle" :blocks="\App\Park\VehicleFields::BLOCKS" :fold="$fold" :required="['brand_id']">
    <x-slot:top>
        <x-park.doc-differences :vehicle="$vehicle" :differences="$differences" class="mb-3"/>
    </x-slot:top>
</x-car.blocks>
@include('park.vehicles.storage-block', ['fold' => $fold])
<x-ui.save-bar page/>
