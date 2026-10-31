{{-- Машина на месте схемы — значок её типа (`Category::icon`), как в таблицах: вписан в место стоя, без поворота (боком
     повёрнутая машина не читается). Легковые — серым (лайм на схеме — действие: куда поставить, что выбрано), остальные типы — своими цветами `.cat-*`.
     Та же раскладка в редакторе — `carIcon` в `resources/js/park/plan/geometry.js`. --}}
@props(['spot', 'category' => null])
@php
    // Значок — квадрат 256: машина в нём во всю ширину и на 0,56 высоты.
    $size = round(min($spot['w'] * 0.84, $spot['h'] * 0.8 / 0.56), 2);
    $cx = $spot['x'] + $spot['w'] / 2;
    $cy = $spot['y'] + $spot['h'] / 2;
@endphp
<g class="plan-car cat-{{ $category?->value ?? 'unknown' }}">
    {!! \App\Support\Icons::svg($category?->icon() ?? 'cat-car', false, '', 'x="'.($cx - $size / 2).'" y="'.($cy - $size / 2).'" width="'.$size.'" height="'.$size.'"') !!}
</g>
