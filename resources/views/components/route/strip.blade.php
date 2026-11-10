{{-- Где сделка на пути — полосой над задачей шага (10.11.2026, владелец: «как им понятнее»): отрезок на этап пути
     (`Path::ladder`), пройденные — зелёные, текущий — лаймовый, впереди — серые. Подписей нет: имя текущего — заголовок
     карточки, весь путь с датами — «Путь сделки» ниже, туда ведёт нажатие. --}}
@props(['blocks', 'position'])
@php
    $blocks = collect($blocks)->values();
    $current = $position?->stage->exits->isNotEmpty() ? $position->stage->block_id : null;
    $at = $current ? $blocks->search(fn ($b) => $b->id === $current) : false;
@endphp
@if ($blocks->count() > 1 && $at !== false)
    <a href="#path" class="route-strip" aria-label="Этап {{ $at + 1 }} из {{ $blocks->count() }}">
        @foreach ($blocks as $i => $b)<span @class(['is-done' => $i < $at, 'is-now' => $i === $at])></span>@endforeach
    </a>
@endif
