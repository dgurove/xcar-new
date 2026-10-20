{{-- Значок связи: машина есть ещё где-то (дело на парковке, предложение в CRM, сделка, гараж, вывоз в логистике; закупка — только пунктом) — зелёные три точки
     у названия, только админу (`CarLinks::shows`). Нажатие — меню «где ещё», пункты ведут туда (`CarLinks::for`).
     Пункты — ленивым фреймом `/live/car-links` по открытию меню: с одной машиной на все дорожки связаны почти все строки, и
     меню на каждой строке стоило 3–4 запроса (486 на «Вывозе», аудит 20.10.2026). Строку таблицы и карточку нажатие не открывает.
     Пункты — не `<a>`: значок стоит внутри ссылки названия, а ссылка в ссылке ломает разбор строки (`menu#go`).
     Слот — свои пункты-действия после переходов (карточка «Наличия»: «Выставить в продажу», `x-park.sale menu`); связей
     нет, а действие есть — значок серый (`is-idle`): зелёный значит «машина есть ещё где-то». --}}
@props(['offer' => null, 'vehicle' => null, 'transport' => null])
@php
    $from = $offer ?? $vehicle ?? $transport;
    $linked = $from && \App\Support\CarLinks::shows($from, auth()->user());
    $extra = $slot->hasActualContent();
@endphp
@if ($from && ($linked || $extra))
    @php $id = 'links-'.($offer ? 'o'.$offer->id : ($vehicle ? 'v'.$vehicle->id : 't'.$transport->id)); @endphp
    <span {{ $attributes->merge(['class' => 'car-links']) }} data-controller="menu">
        <button type="button" class="car-links-btn{{ $linked ? '' : ' is-idle' }}" data-action="menu#toggle:stop:prevent" aria-label="Где ещё это ТС" title="Где ещё это ТС" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon name="chain" class="size-full"/></button>
        <span id="{{ $id }}" class="menu car-links-menu" popover data-menu-target="list" role="menu" data-action="click->menu#close:stop">
            @if ($linked)
                <turbo-frame id="{{ $id }}-items" loading="lazy" src="/live/car-links/{{ $offer ? 'o/'.$offer->id : ($vehicle ? 'v/'.$vehicle->id : 't/'.$transport->id) }}"><span class="menu-item"><span class="skeleton my-2 h-4 w-32"></span></span></turbo-frame>
            @endif
            {{ $slot }}
        </span>
    </span>
@endif
