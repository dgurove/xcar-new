{{-- Номер (убытка, договора) с логотипом вендора перед ним — во второй строке таблиц и строк. Логотип — кнопка: имя
     вендора подсказкой по наведению или нажатию (карточка строки при этом не открывается). Номера нет — один логотип.
     copy — номер копируется нажатием (x-ui.copy-code), как в таблице: плитки и строки предложений. vendor-id — вендор по
     id из `Vendor::badges`. cell — ячейка «Вендор» таблицы (.vendor-ref): есть всегда, номер копируется. --}}
@props(['vendor' => null, 'vendorId' => null, 'ref' => null, 'copy' => false, 'cell' => false])
@php $vendor ??= $vendorId ? \App\Vendors\Vendor::badges()->get($vendorId) : null; @endphp
@if ($cell || $vendor || $ref)
<span {{ $attributes->merge(['class' => $cell ? 'vendor-ref' : 'ref-line']) }}>@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if (($copy || $cell) && $ref)<x-ui.copy-code :value="$ref"/>@elseif (! $cell)<span>{{ $ref }}</span>@endif</span>
@endif
