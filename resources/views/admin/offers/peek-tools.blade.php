{{-- Полоса окошка: «Поделиться»; нечего отдавать (ни видимых фото, ни цены) — кнопки нет. --}}
@if ($offer->visiblePhotos()->isNotEmpty() || $offer->asking_price)<x-offer.share :offer="$offer" icon class="peek-close"/>@endif
