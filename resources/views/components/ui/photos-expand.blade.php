{{-- Подзаголовок ленты кадров — сам кнопка «развернуть» (09.11.2026, владелец: «не текстом, а стрелочкой возле
     подзаголовка, и весь подзаголовок кликабельный»): «Фото от страховой ›», нажали — стрелка вниз, лента плиткой, ещё раз —
     снова лентой. Выбор помнится на устройстве и общий для всех лент (photos_controller, localStorage). Меньше двух кадров —
     подзаголовок без стрелки и не нажимается; без подзаголовка (блок один) — «Фото». when — день, когда загружено больше
     всего кадров блока, серым за подписью: «Фото от страховой 10.11 ›» (13.11.2026, начальник; `PhotoBlocks::day`). --}}
@props(['title' => null, 'when' => null])
<button type="button" {{ $attributes->merge(['class' => 'photos-toggle']) }} data-photos-target="expand" data-action="photos#expand" aria-expanded="false" @if ($title) data-titled @else hidden @endif><span>{{ $title ?? 'Фото' }}</span>@if ($title && $when)<span class="nums text-ink-dim">{{ $when }}</span>@endif<x-ui.icon name="chevron-right" class="photos-toggle-chev size-4"/></button>
