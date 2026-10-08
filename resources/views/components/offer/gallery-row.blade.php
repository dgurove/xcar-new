{{-- Строка CRM «Галереи» (08.10.2026): черновик в окне показа. Название в два этажа; на ПК — вендор с номером убытка,
     город, «Появилось» (gallery_from), «Осталось» (таймер до gallery_until, оранжевый в последние сутки; окно кончилось —
     до какого числа висело, черновик ушёл дальше — его состояние), интерес числом и время последнего отклика. Телефон —
     справа интерес, под ним сколько осталось. --}}
@props(['offer'])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $live = $offer->isGallery();
    $count = (int) ($offer->interests_count ?? $offer->interests()->count());
    $last = $offer->interest_at ?? ($count ? $offer->interests()->max('created_at') : null);
    $last = $last ? \Illuminate\Support\Carbon::parse($last) : null;
    $gone = $offer->state === OfferState::Draft ? 'закрыта '.$offer->gallery_until?->translatedFormat('j M, H:i') : mb_strtolower($offer->state->label());
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="gallery-{{ $n }}" data-offer-number="{{ $n }}">
    <td class="grow">
        <x-ui.row-link :key="$n"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span><x-ui.links :offer="$offer"/></span></x-ui.row-link>
        <span class="cell-sub cell-sub--wrap" data-controller="fitline">
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>
            @if ($offer->gallery_from)<span class="nums sm:hidden">с {{ $offer->gallery_from->translatedFormat('j M, H:i') }}</span>@endif
        </span>
    </td>
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    <td class="col-detail-hide hidden sm:table-cell">@if ($offer->settlement)<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $offer->settlement->name }}</x-ui.place>@endif</td>
    <td class="num nums col-detail-hide hidden whitespace-nowrap sm:table-cell">{{ $offer->gallery_from?->translatedFormat('j M, H:i') }}</td>
    <td class="fill hidden whitespace-nowrap sm:table-cell">
        @if ($live)<span class="nums {{ $offer->isGalleryEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->gallery_until, '') }}</span>
        @else<span class="nums text-ink-muted">{{ $gone }}</span>@endif
    </td>
    <td class="num nums">
        @if ($count)<span class="text-accent-text">{{ $count }}</span>@endif
        <span class="cell-sub sm:hidden nums">@if ($live)<span class="{{ $offer->isGalleryEndingSoon() ? '!text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->gallery_until, '') }}</span>@else{{ $gone }}@endif</span>
    </td>
    <td class="num nums col-detail-hide hidden whitespace-nowrap !text-xs !text-ink-dim sm:table-cell">{{ $last?->translatedFormat('j M, H:i') }}</td>
</tr>
