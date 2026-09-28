{{-- Номер (убытка, договора) с логотипом вендора перед ним — во второй строке таблиц и строк. Логотип — кнопка: имя
     вендора подсказкой по наведению или нажатию (окошко строки при этом не открывается). Номера нет — один логотип. --}}
@props(['vendor' => null, 'ref' => null])
@if ($vendor || $ref)
<span {{ $attributes->merge(['class' => 'ref-line']) }}>@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif{{ $ref }}</span>
@endif
