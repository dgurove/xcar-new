{{-- Коды машины чипами под названием — одни в окне машины CRM, на странице предложения, в окнах парковки и логистики
     (11.11.2026: было три разметки, в окне предложения убыток без логотипа вендора): № предложения (у черновика его нет —
     он ещё не выставлен), убыток или ДЛ с логотипом вендора, VIN, госномер — копируются нажатием. Что не так («нет VIN»,
     ФССП) — слотом после кодов. --}}
@props(['offer' => null, 'vendorId' => null, 'ref' => null, 'vin' => null, 'plate' => null, 'number' => null, 'lease' => false])
@php
    $vendorId ??= $offer?->vendor_id;
    $ref ??= $offer?->claim_ref;
    $vin ??= $offer?->vin;
    $number ??= $offer && \App\Offers\OfferNumber::isPublic($offer) ? (string) $offer->number : null;
    $lease = $lease || (bool) $offer?->leaseRef();
@endphp
@if ($plate)<x-ui.plate :value="$plate" class="cursor-pointer" role="button" tabindex="0" title="Скопировать" data-controller="copy" :data-copy-text-value="$plate" data-copy-done-value="Госномер в буфере" data-action="click->copy#copy:prevent:stop keydown.enter->copy#copy:prevent"/>@endif
@if ($number)<span class="tag nums gap-1">№<x-ui.copy-code :value="$number" done="Номер в буфере"/></span>@endif
@if ($vendorId || $ref)<x-vendor.ref :vendor-id="$vendorId" :ref="$ref" :prefix="$lease ? 'ДЛ' : null" copy class="tag nums"/>@endif
@if ($vin)<x-ui.vin-code :vin="$vin" class="tag"/>@endif
{{ $slot }}
