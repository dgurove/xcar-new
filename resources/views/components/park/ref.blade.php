{{-- Номер убытка с логотипом страховой перед ним — во второй строке таблиц и строк парковки. Логотип — кнопка: имя
     вендора подсказкой по наведению или нажатию (окошко строки при этом не открывается). Номера нет — один логотип. --}}
@props(['vehicle'])
@if ($vehicle->vendor || $vehicle->ref)
<span {{ $attributes->merge(['class' => 'ref-line']) }}>@if ($vehicle->vendor)<button type="button" class="vendor-tip" data-tip="{{ $vehicle->vendor->name }}" aria-label="{{ $vehicle->vendor->name }}"><x-vendor.logo :vendor="$vehicle->vendor"/></button>@endif{{ $vehicle->ref }}</span>
@endif
