{{-- Логотип вендора — квадрат в размер шрифта перед его именем: любой логотип вписан целиком, чтобы все стояли одной
     клеткой. Без загруженного — первая буква имени в той же клетке. Крупнее — классом size-* снаружи (size-9 в строке списка).
     Не `loading="lazy"` (30.10.2026): логотипов единицы, а «ленивую» картинку браузер сторожит каждый кадр прокрутки —
     на «Все» CRM их было полторы тысячи. --}}
@props(['vendor'])
@php $url = $vendor->logoUrl(); @endphp
<span {{ $attributes->merge(['class' => 'vendor-logo'.($url ? '' : ' is-letter')]) }} aria-hidden="true">@if ($url)<img src="{{ $url }}" alt="" decoding="async">@else<span>{{ mb_strtoupper(mb_substr($vendor->name, 0, 1)) }}</span>@endif</span>
