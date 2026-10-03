{{-- Метка «ТС ушла в продажу»: состояние предложения словом, нажатие — само предложение в CRM (другой хост, поэтому
     полная загрузка). Видит только админ: управляющему парковкой продажа не показывается. admin — когда решение уже принято
     снаружи (строка таблицы лежит в общем кэше и не может спрашивать, кто смотрит). --}}
@props(['vehicle', 'admin' => null])
@php
    $admin ??= auth()->user()?->isAdmin();
    $offer = $admin && $vehicle->offer_id ? $vehicle->offer : null;
@endphp
@if ($offer)
    <a {{ $attributes->class(['tag']) }} href="{{ \App\Support\Surface::Crm->url('/offers/'.$offer->number) }}" data-turbo="false" title="Предложение № {{ $offer->number }} в CRM">CRM · {{ mb_strtolower($offer->state->label()) }}</a>
@endif
