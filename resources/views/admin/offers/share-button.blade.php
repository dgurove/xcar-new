{{-- «Поделиться» предложением: нечего отдавать (ни видимых фото, ни цены) — кнопки нет. Одно правило на редактор и карточка. --}}
@if ($offer->visiblePhotos()->isNotEmpty() || $offer->asking_price)
    <x-offer.share :offer="$offer" icon class="{{ $class ?? '' }}"/>
@endif
