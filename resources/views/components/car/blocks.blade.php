{{-- Машина блоками (`Cars\CarFields::BLOCKS`) — одна на CRM и парковку (19.10.2026): какие блоки, решает экран дорожки
     (CRM — «Транспортное средство», «ПТС и СТС», «Характеристики»; парковка — «Транспортное средство», «ПТС и СТС»,
     «Страхователь»). Обёртка без своей коробки (`contents`): блоки встают в сетку экрана. На ней же проверка двойников
     (`twins_controller`): номер убытка, VIN и госномер стоят в разных блоках, а строки «такая ТС уже есть» — под VIN. --}}
@props(['lane' => null, 'of' => null, 'blocks', 'values' => [], 'fold' => null, 'hints' => [], 'required' => [], 'as' => 'card', 'top' => null, 'candidate' => null])
@php
    $of ??= $lane ? $lane::class : null;
    $twins = in_array('car', $blocks, true) ? ($of === \App\Offers\Offer::class ? '/offers/twins' : '/reference/twins') : null;
@endphp
<div class="contents" @if ($twins) data-controller="twins" data-twins-url-value="{{ $twins }}" data-twins-except-value="{{ $lane?->id }}" data-twins-candidate-value="{{ $candidate }}" data-action="input->twins#changed" @endif>
    @foreach ($blocks as $block)
        <x-car.block :lane="$lane" :of="$of" :block="$block" :values="$values" :fold="$fold" :hints="$hints" :required="$required" :as="$as"
            :top="$block === 'car' ? $top : null" :id="$block === 'papers' ? 'car-docs' : null" @class(['scroll-mt-24' => $block === 'papers'])/>
    @endforeach
</div>
