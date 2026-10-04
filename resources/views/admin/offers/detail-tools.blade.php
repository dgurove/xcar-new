{{-- Полоса карточки: «Поделиться» (✨ — в блоке «Документы», в полосе его нет). Её же перерисовывает загрузка кадров
     и документов (gallery-stream). --}}
{{-- Модератор черновики не делит. --}}
@if (auth()->user()->canManageCrm())@include('admin.offers.share-button', ['class' => 'bar-btn'])@endif
