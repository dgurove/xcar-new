{{-- Кнопка «Фильтр» шапки вкладок колеса (17.11.2026, владелец: «справа вид и ФИЛЬТР»; окном — «фильтр видом окошка, как
     предложение»): ссылка на тот же список с `?peek=filter` — окно `ui/filter-body` во фрейме карточки, открывает
     `detail#filter` (на ПК колонка справа, на телефоне лист снизу; открытый фильтр нажатие закрывает). Выбрано что-то не по
     умолчанию — лаймовая «Фильтр 3 ×»: × — сбросить к умолчанию (одна кнопка сброса на экран, владелец 02.11.2026: «зачем
     две»); открыт сохранённый фильтр — его название. --}}
@props(['facets'])
@php
    $n = $facets->activeCount();
    $match = $facets->savedHere();
    $on = $n > 0 || $match;
@endphp
<span @class(['pill filter-btn', 'facet-on' => $on]) @if ($on) aria-current="true" @endif>
    <a href="{{ \App\Support\Detail::url(\App\Support\Detail::FILTER) }}" class="facet-open" data-action="detail#filter" data-turbo-prefetch="false" aria-label="Фильтр">
        <x-ui.icon name="filter" class="size-4 shrink-0"/><span class="truncate">{{ $match?->name ?? 'Фильтр' }}</span>@if ($n && ! $match)<span class="nums">{{ $n }}</span>@endif
    </a>
    @if ($n)
        <a href="{{ $facets->resetUrl() }}" class="facet-x" data-turbo-action="replace" data-turbo-prefetch="false" aria-label="Сбросить фильтр"><x-ui.icon name="x" class="size-3.5"/></a>
    @endif
</span>
