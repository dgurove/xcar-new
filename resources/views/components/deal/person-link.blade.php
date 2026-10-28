{{-- Ссылка тому, кому отдали машину, — строкой в карточке сделки и гаража CRM (28.10.2026, владелец: прислать позже):
     его сделка или машина в гараже на сайте (`Deal::personUrl`; не вошёл — вход и сюда же). «Скопировать» и
     «Отправить» текстом (`Deal::personText`). Та же ссылка сразу после «Отдать» — шторкой `x-deal.handed`. Только админу. --}}
@props(['deal'])
@php $url = auth()->user()?->canManageCrm() ? $deal->personUrl() : null; @endphp
@if ($url && $deal->buyer)
    <div {{ $attributes->class(['list']) }}>
        <x-ui.share-link variant="row" :url="$url" :title="$deal->offer->titleWithYear()" done="Ссылка в буфере" :text="$deal->personText()" :label="'Ссылка для '.$deal->buyer->shortName()"/>
    </div>
@endif
