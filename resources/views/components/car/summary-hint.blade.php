{{-- Спор поля в сводке машины (`x-car.summary`): «или Белый» словами, нажатие — шторка «Данные ТС», где у поля своя шторка
     вариантов (`x-car.variants`; из закрытой шторки диалог вариантов не открыть). --}}
@props(['v' => null, 'sheet', 'edit' => true])
@if ($v && ! $edit)
    {{-- Кто машину не правит (приёмщик, закрытое дело) — спор словами, без кнопки: шторки правки у него нет. --}}
    <span class="car-summary-hint"><x-car.variant-hint :v="$v" plain/></span>
@elseif ($v)
    <button type="button" class="car-summary-hint" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open"><x-car.variant-hint :v="$v" plain/></button>
@endif
