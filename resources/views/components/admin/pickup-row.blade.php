{{-- Строка «Работы → Вывоза»: название в два этажа — под ним шаг вывоза цветным словом и дни (`PickupState::crmLine`), на
     телефоне логотип вендора с убытком тем же этажом; от 640 — вендор столбцом, справа — куда везём. Подробная (и широкий
     ПК) — ещё город и с какого дня на шаге. --}}
@props(['offer'])
@php
    [$word, $color, $days] = \App\Offers\PickupState::crmLine($offer);
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $hasRef = $vendor || $offer->claim_ref;
    $since = $offer->position(\App\Workflow\Track::Service)?->block_entered_at;
@endphp
<tr data-detail-key="{{ $offer->number }}" id="pickup-{{ $offer->number }}">
    <td class="grow">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub"><span class="{{ $color }}">{{ $word }}{{ $days ? ', '.$days : '' }}</span>@if ($hasRef)<span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>@endif</span>
    </td>
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    <td class="cell-dim hidden lg:table-cell col-detail-hide">{{ $offer->settlement?->title() }}</td>
    <td class="num nums cell-dim hidden xl:table-cell col-detail-hide">{{ $since?->translatedFormat('j M') }}</td>
    <td class="num text-sm text-ink-muted">{{ mb_strtolower($offer->pickupDestination()->label()) }}</td>
</tr>
