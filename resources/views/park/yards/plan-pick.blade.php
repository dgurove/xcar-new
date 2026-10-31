{{-- Схема для выбора места в шторке (`plan-pick-frame`): свободное место — нажатием в поле «Место», место ТС сейчас
     отмечено. Парковка без схемы — пустой фрейм. --}}
<turbo-frame id="{{ request()->header('Turbo-Frame', 'plan-pick') }}">
    @if ($yard->rows)
        <div class="plan-pick" data-controller="yard-plan" data-yard-plan-mode-value="pick" data-yard-plan-vehicle-value="{{ $vehicle }}">
            <x-park.yard-plan :yard="$yard" :cars="$cars" mode="pick" :current="$at"/>
            <div class="plan-zoom">
                <button type="button" class="plan-zoom-btn" data-action="yard-plan#zoomIn" aria-label="Ближе"><x-ui.icon name="plus" class="size-5"/></button>
                <button type="button" class="plan-zoom-btn" data-action="yard-plan#zoomOut" aria-label="Дальше"><span class="plan-minus"></span></button>
            </div>
        </div>
    @endif
</turbo-frame>
