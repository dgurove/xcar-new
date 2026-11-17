{{-- Машина строкой на странице человека в «Оплатах» (18.11.2026, владелец: «список как в предложениях, по машинам, а не по
     плательщикам»): фото, марка и модель с годом, ниже логотип вендора с номером убытка и что с оплатой словом (`now`, тон `tone`);
     справа сумма. Нажатие — окно машины рядом (`?peek=номер`, `CarWindow`); без машины (разовая услуга, вывоз без предложения) —
     карточка счёта в «Оплатах». Слот `acts` — кнопки своей строкой под ней (не справа: действие — настоящей кнопкой). --}}
@props(['invoice', 'now' => null, 'tone' => '', 'amount', 'amountTone' => '', 'tab'])
@php
    use App\Support\Money;
    $i = $invoice;
    $offer = $i->carOffer();
    $title = $offer ? $offer->title() : (\App\Http\Admin\MoneyRows::what($i) ?? \App\Http\Admin\MoneyRows::payoutKind($i));
    $year = $offer?->year;
@endphp
<div class="money-car">
    <a href="{{ $offer ? \App\Support\Detail::url($offer->number, ['tab' => $tab]) : \App\Http\Admin\MoneyController::url($i) }}" class="row"
        @if ($offer) data-detail-key="{{ $offer->number }}" data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false" @else data-turbo-frame="_top" @endif>
        <span class="row-photo row-photo-s">@if ($offer)<x-offer.photo :media="$offer->mainPhoto()" sizes="48px"/>@endif</span>
        <span class="min-w-0 flex-1">
            <span class="block truncate">{{ $title }}@if ($year)<span class="text-ink-muted"> {{ $year }}</span>@endif</span>
            <span class="row-sub !whitespace-normal">
                @if ($offer)<x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/>@endif
                @if ($now)<span class="{{ $tone }}">{{ $now }}</span>@endif
            </span>
        </span>
        <span class="nums shrink-0 {{ $amountTone }}">{{ Money::rub($amount) }}</span>
    </a>
    @isset($acts)<div class="money-car-acts">{{ $acts }}</div>@endisset
</div>
