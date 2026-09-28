{{-- Номер убытка с логотипом страховой перед ним — во второй строке таблиц и строк парковки (x-vendor.ref). --}}
@props(['vehicle'])
<x-vendor.ref :vendor="$vehicle->vendor" :ref="$vehicle->ref" {{ $attributes }}/>
