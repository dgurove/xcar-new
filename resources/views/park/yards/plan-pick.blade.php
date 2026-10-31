{{-- Схема для выбора места в шторке (`plan-pick-frame`): свободное место — нажатием в поле «Место», место ТС сейчас
     отмечено. Отдельным блоком под полями, высотой по форме схемы. Парковка без схемы — пустой фрейм. --}}
{{-- Без пробелов внутри фрейма у парковки без схемы: загруженный пустой фрейм не занимает строку формы (`.plan-pick-frame[complete]:empty`; до загрузки он виден — иначе ленивый фрейм не грузится). --}}
<turbo-frame id="{{ request()->header('Turbo-Frame', 'plan-pick') }}">@if ($yard->rows)
    <div class="plan-pick" data-controller="yard-plan" data-yard-plan-mode-value="pick" data-yard-plan-vehicle-value="{{ $vehicle }}">
        <x-park.yard-plan :yard="$yard" :cars="$cars" mode="pick" :current="$at"/>
        @include('park.yards.plan-zoom', ['controller' => 'yard-plan'])
    </div>
@endif</turbo-frame>
