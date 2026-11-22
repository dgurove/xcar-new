{{-- «Поделиться» предложением: нечего отдавать (ни видимых фото, ни цены) — кнопки нет. Одно правило на редактор и карточка. --}}
@if ($offer->visiblePhotos()->isNotEmpty() || $offer->asking_price)
    {{-- На странице машины — и ссылка менеджеру на сделку или гараж (вместо карточки «Ссылка менеджеру», 23.11.2026). --}}
    @php
        $linkDeal = ($admin ?? false) ? (($garageCar ?? null)?->deal ?? ($deal ?? null)) : null;
        $personLink = $linkDeal?->buyer && auth()->user()?->canEditDeals() ? $linkDeal->personUrl() : null;
    @endphp
    <x-offer.share :offer="$offer" icon class="{{ $class ?? '' }}" :person-link="$personLink"/>
@endif
