{{-- Метка на кадре витрины — только где ТС. Новое и горящее — полосой у плитки и точкой у строки, срок и «Приём закрыт» —
     строкой под названием (x-offer.when, владелец 10.10.2026). --}}
@props(['offer'])
@if ($offer->car_place?->publicLabel())<x-ui.place class="mark mark-glass">{{ $offer->car_place->publicLabel() }}</x-ui.place>@endif
