@props(['offer'])
<a href="/admin/offers/{{ $offer->number }}" class="tile" id="offer-tile-{{ $offer->number }}">
    <div class="tile-photo">
        <x-offer.photo :media="$offer->mainPhoto()"/>
        <span class="absolute left-2 top-2 chip bg-chrome/70 text-white">№ {{ $offer->number }}</span>
        @if ($offer->active_bids_count)<span class="absolute right-2 top-2 badge">{{ $offer->active_bids_count }}</span>@endif
    </div>
    <div class="tile-body">
        <div class="flex items-start justify-between gap-2">
            <span class="font-medium">{{ $offer->titleWithYear() }}</span>
            <x-offer.state :state="$offer->state" class="shrink-0"/>
        </div>
        <span class="text-sm text-ink-muted">{{ implode(' · ', array_slice($offer->facts(), 1, 3)) }}</span>
        <div class="mt-auto flex items-center justify-between pt-1">
            <x-offer.price :amount="$offer->asking_price"/>
            @if ($offer->state === \App\Offers\OfferState::Open && $offer->bids_close_at)
                <span class="text-sm text-ink-muted" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}"></span>
            @endif
        </div>
    </div>
</a>
