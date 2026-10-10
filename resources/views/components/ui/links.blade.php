{{-- Значок связи: машина есть ещё где-то (дело на парковке, предложение в CRM, сделка, гараж; закупка — только пунктом) — зелёные три точки
     у названия, только админу (`CarLinks::shows`). Нажатие — меню «где ещё», пункты ведут туда (`CarLinks::for`).
     Значок только у связанных машин — их единицы, меню рисуется сразу. Строку таблицы и карточку нажатие не открывает.
     Пункты — не `<a>`: значок стоит внутри ссылки названия, а ссылка в ссылке ломает разбор строки (`menu#go`).
     Слот — свои пункты-действия после переходов (карточка «Наличия»: «Выставить в продажу», `x-park.sale menu`); связей
     нет, а действие есть — значок серый (`is-idle`): зелёный значит «машина есть ещё где-то». --}}
@props(['offer' => null, 'vehicle' => null])
@php
    $from = $offer ?? $vehicle;
    $linked = $from && \App\Support\CarLinks::shows($from, auth()->user());
    $extra = $slot->hasActualContent();
@endphp
@if ($from && ($linked || $extra))
    @php $id = 'links-'.($offer ? 'o'.$offer->id : 'v'.$vehicle->id); @endphp
    <span {{ $attributes->merge(['class' => 'car-links']) }} data-controller="menu">
        <button type="button" class="car-links-btn{{ $linked ? '' : ' is-idle' }}" data-action="menu#toggle:stop:prevent" aria-label="Где ещё это ТС" title="Где ещё это ТС" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon name="chain" class="size-full"/></button>
        <span id="{{ $id }}" class="menu car-links-menu" popover data-menu-target="list" role="menu" data-action="click->menu#close:stop">
            @foreach ($linked ? \App\Support\CarLinks::for($from) : [] as $link)
                <span class="menu-item cursor-pointer" role="menuitem" tabindex="0" data-href="{{ $link['href'] }}" data-action="click->menu#go keydown.enter->menu#go">
                    <span class="min-w-0 flex-1 py-2"><span class="block">{{ $link['title'] }}</span><span class="block text-xs text-ink-muted">{{ $link['state'] }}</span></span>
                    <x-ui.chevron/>
                </span>
            @endforeach
            {{ $slot }}
        </span>
    </span>
@endif
