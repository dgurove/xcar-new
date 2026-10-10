{{-- Ответ во фрейм поиска шапки: тот же id, что у запросившего фрейма (на ПК и в шторке телефона они разные). --}}
<turbo-frame id="{{ request()->header('Turbo-Frame') }}">@include('search.results', ['groups' => $groups, 'q' => $q])</turbo-frame>
