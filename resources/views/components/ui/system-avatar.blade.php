{{-- Аватар системы — там, где у записи нет человека (26.10.2026, владелец: «пустота мне не нравится»): лаймовый кружок с
     белым значком на месте аватара сотрудника. via — откуда: `mail` — завела почта (конверт), остальное (Мигторг, парковка,
     часы) — искра ✨. Размер — как у x-ui.avatar рядом. --}}
@props(['via' => null, 'size' => 20])
@php
    $mail = $via === 'mail';
    $title = match ($via) { 'mail' => 'Завела почта', 'migtorg' => 'С Мигторга', 'park' => 'С парковки', default => 'Автоматически' };
    $icon = max(10, (int) round($size * .62));
@endphp
<span {{ $attributes->merge(['class' => 'avatar avatar-system', 'title' => $title]) }} style="width: {{ $size }}px; height: {{ $size }}px">
    @if ($mail)<x-ui.icon name="mail" fill style="width: {{ $icon }}px; height: {{ $icon }}px"/>@else<x-ui.spark style="width: {{ $icon }}px; height: {{ $icon }}px"/>@endif
</span>
