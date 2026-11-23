{{-- Шторка «Данные ТС» — одна для CRM и парковки (23.11.2026, владелец: «приводи всё к единым модулям»): поля машины разделами
     подряд (слот — `x-car.blocks as=section` и своё дорожки: у продажи «Осмотр», у парковки «Хранение»), внизу «Сохранить» формы
     `form`. Шторка стоит внутри своей формы (поля — её поля) или рядом с `form=`. Открывают «Изменить» и споры сводки
     (`x-car.summary`, событие `{id}:open`); `open` — открыта сама (ошибка в её полях, подсветка Мигторга). `part` — имена полей
     для `data-save-part` (CRM: уходят её поля и тронутые; парковка частичное сохранение не умеет — вся форма). --}}
@props(['id', 'form', 'open' => false, 'part' => null])
<div class="contents" data-controller="sheet" data-action="{{ $id }}:open@window->sheet#open">
    <x-ui.sheet :id="$id" title="Данные ТС" wide :open="(bool) $open">
        <div class="flex flex-col gap-5">{{ $slot }}</div>
        <x-ui.sheet-foot type="submit" foot="mt-4" :form="$form" data-action="sheet#close" :data-save-part="$part">Сохранить</x-ui.sheet-foot>
    </x-ui.sheet>
</div>
