{{-- Кнопка «Фильтр» шапки вкладок колеса (17.11.2026, владелец: «справа вид и ФИЛЬТР», «в кнопке фильтр — более навороченный
     фильтр, как на xcar, по многим параметрам, с сохранением параметров с вводом названия»). Выбрано что-то не по умолчанию —
     лаймовая «Фильтр 3 ×»: × — сбросить к умолчанию (одна кнопка сброса на экран, владелец 02.11.2026: «зачем две»); открыт
     сохранённый фильтр — его название. Шторка (на ПК с мышью — поповер) грузится по открытию: тот же адрес с `X-Filters`
     отвечает `ui/filter-body` (`Facets::apply`) — варианты всех параметров считаются, только когда их смотрят. --}}
@props(['facets', 'name' => 'list'])
@php
    $n = $facets->activeCount();
    $user = auth()->user();
    $saved = $facets->saved && $user ? \App\Support\Facets\SavedFilter::of($user, $facets->saved) : collect();
    $carry = collect(request()->query())->only(['preset'])->filter(fn ($v) => is_string($v) && $v !== '')->all();
    $match = $saved->first(fn ($f) => $f->matches([...$carry, ...$facets->state()]));
    $on = $n > 0 || $match;
    $id = 'filter-'.$name;
@endphp
<div class="contents" data-controller="sheet filter" data-filter-url-value="{{ $facets->url([]) }}" data-action="filter:open@window->filter#show">
    <span @class(['pill filter-btn', 'facet-on' => $on]) @if ($on) aria-current="true" @endif>
        <button type="button" class="facet-open" data-filter-target="button" data-action="filter#load sheet#open" aria-controls="{{ $id }}">
            <x-ui.icon name="filter" class="size-4 shrink-0"/><span class="truncate">{{ $match?->name ?? 'Фильтр' }}</span>@if ($n && ! $match)<span class="nums">{{ $n }}</span>@endif
        </button>
        @if ($n)
            <a href="{{ $facets->resetUrl() }}" class="facet-x" data-turbo-action="replace" data-turbo-prefetch="false" aria-label="Сбросить фильтр"><x-ui.icon name="x" class="size-3.5"/></a>
        @endif
    </span>
    <x-ui.sheet :id="$id" title="Фильтр" anchor class="sheet-filter">
        <div class="filter-body" data-filter-target="body">
            <div class="list">@for ($i = 0; $i < 6; $i++)<div class="row"><span class="skeleton h-4 w-32"></span></div>@endfor</div>
        </div>
    </x-ui.sheet>
</div>
