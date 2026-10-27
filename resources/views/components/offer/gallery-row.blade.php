{{-- Строка CRM «Галереи» (08.10.2026): черновик в окне показа. Название в два этажа; на ПК — вендор с номером убытка,
     город, «Появилось» (gallery_from), «Осталось» (таймер до gallery_until, оранжевый в последние сутки; окно кончилось —
     до какого числа висело, черновик ушёл дальше — его состояние), интерес числом и время последнего отклика. Телефон —
     справа интерес, под ним сколько осталось. Столбцы — `GalleryController::table`. --}}
@props(['offer', 'table'])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $live = $offer->isGallery();
    $count = (int) ($offer->interests_count ?? $offer->interests()->count());
    $last = $offer->interest_at ?? ($count ? $offer->interests()->max('created_at') : null);
    $last = $last ? \Illuminate\Support\Carbon::parse($last) : null;
    $gone = $offer->state === OfferState::Draft ? 'закрыта '.$offer->gallery_until?->translatedFormat('j M, H:i') : $offer->crmTab();
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="gallery-{{ $n }}" data-offer-number="{{ $n }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$n"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span><x-ui.links :offer="$offer"/></span></x-ui.row-link>
        <span class="cell-sub cell-sub--wrap" data-controller="fitline">
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/></span>
            @if ($offer->gallery_from)<span class="nums sm:hidden">с {{ $offer->gallery_from->translatedFormat('j M, H:i') }}</span>@endif
        </span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">@if ($offer->settlement)<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $offer->settlement->name }}</x-ui.place>@endif</td>
        @break
        @case('from')
    <td class="{{ $col->classes() }}">{{ $offer->gallery_from?->translatedFormat('j M, H:i') }}</td>
        @break
        @case('left')
    <td class="{{ $col->classes() }}">
        @if ($live)<span class="nums {{ $offer->isGalleryEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->gallery_until, '') }}</span>
        @else<span class="nums text-ink-muted">{{ $gone }}</span>@endif
    </td>
        @break
        @case('interest')
    <td class="{{ $col->classes() }}">
        @if ($count)<span class="text-accent-text">{{ $count }}</span>@endif
        <span class="cell-sub sm:hidden nums">@if ($live)<span class="{{ $offer->isGalleryEndingSoon() ? '!text-urgent' : '' }}" data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->gallery_until, '') }}</span>@else{{ $gone }}@endif</span>
    </td>
        @break
        @case('last')
    <td class="{{ $col->classes() }}">{{ $last?->translatedFormat('j M, H:i') }}</td>
        @break
        @endswitch
    @endforeach
</tr>
