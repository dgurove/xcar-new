{{-- Сведения шапки окна и страницы машины — строками со значком (12.11.2026, владелец про фразу «Санкт-Петербург, 78, у
     владельца, Владислав Митрофанов, без оплаты, счёт ПРАЙМ при продаже, 8 дней на этапе»: «неадекватно»): где машина, кто
     её держит (`CarView::holder`) и на каких условиях, приём и галерея, публикация для других. Каждое — один раз: ниже в окне
     не повторяется (шаг пути говорит «Менеджер», срок шага — в шаге). Вид — `x-ui.info`, правило «сведения значками». --}}
@php
    $holder = $car->holder();
    $left = $gallery || ! $admin || ! $offer->state->acceptsBids() ? null : $offer->secondsLeft();
    $place = $offer->settlement?->title() ?? $offer->car_place?->label();
    ob_start();
@endphp
@if ($place)
    <x-ui.info-row icon="map-pin" :sub="$offer->settlement && $offer->car_place ? mb_strtolower($offer->car_place->label()) : null">{{ $place }}</x-ui.info-row>
@endif
@if ($holder)
    <div class="info-row">
        @if ($holder['user'])<x-ui.avatar :user="$holder['user']" :size="20" class="mt-px shrink-0"/>@else<x-ui.icon name="user" class="info-icon size-5"/>@endif
        <span class="min-w-0 flex-1">
            <span class="block break-words">{{ $holder['name'] }}</span>
            <span class="info-sub">{{ $holder['role'] }}</span>
        </span>
        @if ($holder['user']?->phone)<x-ui.phone :user="$holder['user']" class="shrink-0 text-sm"/>@endif
    </div>
@endif
@if (! $intake && $left !== null && $left > 0)
    <x-ui.info-row icon="clock"><span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : '' }}">приём <span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at, 'ещё') }}</span></span></x-ui.info-row>
@elseif (! $intake && ! $gallery && $car->selling() && $offer->closed() && $admin)
    <x-ui.info-row icon="clock"><span class="text-ink-muted">приём закрыт</span></x-ui.info-row>
@endif
{{-- Окно галереи (08.10.2026): висит — сколько осталось, кончилось — до какого числа висело. --}}
@if ($gallery)
    <x-ui.info-row icon="grid"><span class="nums {{ $offer->isGalleryEndingSoon() ? 'text-urgent' : 'text-accent-text' }}">в галерее <span data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="закрыта" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->gallery_until, 'ещё') }}</span></span></x-ui.info-row>
@elseif ($offer->gallery_from && $car->selling())
    <x-ui.info-row icon="grid"><span class="nums text-ink-muted">в галерее до {{ \App\Support\Ago::html($offer->gallery_until, true, words: false) }}</span></x-ui.info-row>
@endif
{{-- Опубликована для других менеджеров поверх сделки (`ShowDealToAll`, 12.11.2026). --}}
@if ($offer->showsDealAsOpen())
    <x-ui.info-row icon="flag"><span class="nums text-accent-text">у других менеджеров до {{ \App\Support\Ago::html($offer->deal_show_until, true, words: false) }}</span></x-ui.info-row>
@endif
@php $rows = trim(ob_get_clean()); @endphp
@if ($rows !== '')<x-ui.info :class="$class ?? 'w-full'">{!! $rows !!}</x-ui.info>@endif
