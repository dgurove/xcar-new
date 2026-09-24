{{-- Имя вендора с логотипом перед ним — в чипе (class="tag"), строке, ячейке. party — контрагент счёта: логотип, только
     если это контрагент вендора, имя — контрагента. --}}
@props(['vendor' => null, 'party' => null])
@php
    $vendor ??= $party ? \App\Vendors\Vendor::ofParty($party->id) : null;
    $name = $party?->name ?? $vendor?->name;
@endphp
<span {{ $attributes->merge(['class' => 'vendor-name']) }}>@if ($vendor)<x-vendor.logo :vendor="$vendor"/>@endif<span class="vendor-name-text">{{ $name }}</span></span>
