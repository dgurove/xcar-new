{{-- Значок нашей парковки — знак «P» приложения park.xcar (`/images/park.svg`, в тёмной теме — белый) перед названием
     парковки: «Санкт-Петербург, 78, [P] Краснопутиловская» в окне машины (13.11.2026, начальник). Высота — классом. --}}
@props(['class' => 'h-3.5'])
<span class="on-light shrink-0"><img src="/images/park.svg" alt="" width="960" height="1024" class="{{ $class }} w-auto"></span><span class="on-dark shrink-0"><img src="/images/park-white.svg" alt="" width="960" height="1024" class="{{ $class }} w-auto"></span>
