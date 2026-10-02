{{-- Номер (убытка, договора) с логотипом вендора перед ним — во второй строке таблиц и строк. Логотип — кнопка: имя
     вендора подсказкой по наведению или нажатию (окошко строки при этом не открывается). Номера нет — один логотип.
     copy — номер копируется нажатием (x-ui.copy-code), как в таблице: плитки и строки предложений. --}}
@props(['vendor' => null, 'ref' => null, 'copy' => false])
@if ($vendor || $ref)
<span {{ $attributes->merge(['class' => 'ref-line']) }}>@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($copy && $ref)<x-ui.copy-code :value="$ref"/>@else{{ $ref }}@endif</span>
@endif
