{{-- Логотип вендора — квадрат в размер шрифта перед его именем: любой логотип вписан целиком, чтобы все стояли одной
     клеткой. Без загруженного — первая буква имени в той же клетке. Крупнее — классом size-* снаружи (size-9 в строке списка). --}}
@props(['vendor'])
@php $url = $vendor->logoUrl(); @endphp
<span {{ $attributes->merge(['class' => 'vendor-logo'.($url ? '' : ' is-letter')]) }} aria-hidden="true">@if ($url)<img src="{{ $url }}" alt="" loading="lazy" decoding="async">@else<span>{{ mb_strtoupper(mb_substr($vendor->name, 0, 1)) }}</span>@endif</span>
