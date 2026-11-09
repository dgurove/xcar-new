{{-- Подзаголовок ленты кадров — сам кнопка «развернуть» (09.11.2026, владелец: «не текстом, а стрелочкой возле
     подзаголовка, и весь подзаголовок кликабельный»): «Фото от страховой ›», нажали — стрелка вниз, лента плиткой, ещё раз —
     снова лентой. Выбор помнится на устройстве и общий для всех лент (photos_controller, localStorage). Меньше двух кадров —
     подзаголовок без стрелки и не нажимается; без подзаголовка (блок один) — «Фото». --}}
@props(['title' => null])
<button type="button" {{ $attributes->merge(['class' => 'photos-toggle']) }} data-photos-target="expand" data-action="photos#expand" aria-expanded="false" @if ($title) data-titled @else hidden @endif><span>{{ $title ?? 'Фото' }}</span><x-ui.icon name="chevron-right" class="photos-toggle-chev size-4"/></button>
