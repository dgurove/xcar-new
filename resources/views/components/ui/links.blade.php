{{-- Значок связи: машина есть ещё где-то (дело на парковке, предложение в CRM, сделка, гараж, закупка) — зелёные три точки
     у названия, только админу (`CarLinks::shows`). Нажатие — меню «где ещё», пункты ведут туда (`CarLinks::for`).
     Значок только у связанных машин — их единицы, меню рисуется сразу. Строку таблицы и карточку нажатие не открывает.
     Пункты — не `<a>`: значок стоит внутри ссылки названия, а ссылка в ссылке ломает разбор строки (`menu#go`). --}}
@props(['offer' => null, 'vehicle' => null])
@php $from = $offer ?? $vehicle; @endphp
@if ($from && \App\Support\CarLinks::shows($from, auth()->user()))
    @php $id = 'links-'.($offer ? 'o'.$offer->id : 'v'.$vehicle->id); @endphp
    <span {{ $attributes->merge(['class' => 'car-links']) }} data-controller="menu">
        <button type="button" class="car-links-btn" data-action="menu#toggle:stop:prevent" aria-label="Где ещё эта машина" title="Где ещё эта машина" aria-haspopup="menu" aria-controls="{{ $id }}"><x-ui.icon name="chain" class="size-full"/></button>
        <span id="{{ $id }}" class="menu car-links-menu" popover data-menu-target="list" role="menu" data-action="click->menu#close:stop">
            @foreach (\App\Support\CarLinks::for($from) as $link)
                <span class="menu-item cursor-pointer" role="menuitem" tabindex="0" data-href="{{ $link['href'] }}" data-action="click->menu#go keydown.enter->menu#go">
                    <span class="min-w-0 flex-1 py-2"><span class="block">{{ $link['title'] }}</span><span class="block text-xs text-ink-muted">{{ $link['state'] }}</span></span>
                    <x-ui.chevron/>
                </span>
            @endforeach
        </span>
    </span>
@endif
