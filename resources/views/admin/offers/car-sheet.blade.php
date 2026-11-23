{{-- Шторка «Данные ТС» страницы машины (23.11.2026, владелец: «как выбор фото сделали в отдельном меню, так и редактирование
     деталей машины»; из макетов — «одним листом»): все поля машины разделами подряд — «Транспортное средство», «ПТС и СТС»,
     «Характеристики» (`x-car.blocks`, как в окне машины) и «Осмотр» (`fields.sale`), внизу «Сохранить». Стоит внутри формы
     страницы (`#offer-form`): поля — её поля, «Сохранить изменения» плашки шлёт их как прежде, ошибки возвращаются в поля.
     «Сохранить» шторки шлёт её поля и тронутые (`data-save-part`, как «Показ»): ошибка в чужом блоке страницы правку ТС не
     роняет. Открывают её «Изменить» и споры сводки (`x-car.summary`, событие `{id}:open`); открыта сама — ошибка в её полях
     или подсветка Мигторга по ним (раньше так же раскрывался блок). Окно машины зовёт её со своей формой (`carForm` —
     `detail-edit-{n}`, частичное сохранение) и обязательными (`carRequired`). --}}
@php
    $sheetId = 'car-edit-'.$offer->number;
    $carForm ??= 'offer-form';
    $carRequired ??= $published ? [] : ['vendor_id', 'claim_ref', 'brand_id'];
    $saleKeys = ['settlement_id', 'sale_settlement_id', 'inspection_address', 'show_address', 'description'];
    $carKeys = [...\App\Cars\CarFields::names(\App\Offers\Offer::class, 'car', 'papers', 'specs'), 'show_vin', ...$saleKeys];
    $carOpen = ($errors->hasAny($carKeys) && ! str_starts_with((string) old('_form'), 'handover-')) || array_intersect($hints ?? [], $carKeys);
@endphp
<x-car.edit-sheet :id="$sheetId" :form="$carForm" :open="$carOpen" :part="implode(' ', $carKeys)">
    <x-car.blocks :lane="$offer" :blocks="['car', 'papers', 'specs']" as="section" :hints="$hints ?? []" :required="$carRequired"/>
    @include('admin.offers.fields.sale', ['as' => 'section', 'fold' => null, 'form' => $carForm])
</x-car.edit-sheet>
