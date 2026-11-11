{{-- «Поделиться» в полосе окна машины (✨ — в блоке «Документы», в полосе его нет). Свой `#detail-share`: загрузка кадров
     и документов (`gallery-stream`) перерисовывает только её, а не всю полосу с «⋯» (11.11.2026). Модератор черновики не делит. --}}
<span id="detail-share" class="contents">@if (auth()->user()->canManageCrm())@include('admin.offers.share-button', ['class' => 'bar-btn'])@endif</span>
