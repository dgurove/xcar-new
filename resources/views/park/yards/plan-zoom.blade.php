{{-- Масштаб схемы — капсулой в углу холста: ближе, дальше, вся парковка. На телефоне и в шторке — только «вся парковка»
     (ближе и дальше там щипком). $controller — чей контроллер зовут кнопки. --}}
<div class="plan-zoom">
    <button type="button" class="plan-zoom-btn plan-zoom-step" data-action="{{ $controller }}#zoomIn" aria-label="Ближе"><x-ui.icon name="plus" class="size-4"/></button>
    <button type="button" class="plan-zoom-btn plan-zoom-step" data-action="{{ $controller }}#zoomOut" aria-label="Дальше"><span class="plan-minus"></span></button>
    <button type="button" class="plan-zoom-btn" data-action="{{ $controller }}#fit" aria-label="Вся парковка"><x-ui.icon name="expand" class="size-4"/></button>
</div>
