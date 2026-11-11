{{-- Коды машины чипами под названием — одни в окне машины CRM, на странице предложения, в окнах парковки и логистики
     (11.11.2026: было три разметки, в окне предложения убыток без логотипа вендора): № предложения (у черновика его нет —
     он ещё не выставлен), убыток или ДЛ с логотипом вендора (номера нет — логотип с именем), VIN — копируются нажатием.
     Госномер — не здесь, а сразу за названием (`x-ui.plate copy`, вариант D3). Что не так (`x-park.alerts`) — слотом. --}}
@props(['offer' => null, 'vendorId' => null, 'ref' => null, 'vin' => null, 'number' => null, 'lease' => false])
@php
    $vendorId ??= $offer?->vendor_id;
    $ref ??= $offer?->claim_ref;
    $vin ??= $offer?->vin;
    $number ??= $offer && \App\Offers\OfferNumber::isPublic($offer) ? (string) $offer->number : null;
    $lease = $lease || (bool) $offer?->leaseRef();
@endphp
@if ($number)<span class="tag nums gap-1">№<x-ui.copy-code :value="$number" done="Номер в буфере"/></span>@endif
@if ($ref)<x-vendor.ref :vendor-id="$vendorId" :ref="$ref" :prefix="$lease ? 'ДЛ' : null" copy class="tag nums"/>
@elseif ($vendorId && ($vendor = \App\Vendors\Vendor::badges()->get($vendorId)))<x-vendor.name :vendor="$vendor" class="tag"/>@endif
@if ($vin)<x-ui.vin-code :vin="$vin" class="tag"/>@endif
{{ $slot }}
