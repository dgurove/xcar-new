{{-- Фото черновика у поставщика (08.11.2026, владелец: редактор — «халтура», фото были мелкой полоской): главный кадр крупно
     4:3 — нажатие открывает просмотр, под ним лента миниатюр с «+», порядком, звездой и корзиной своих; файлы можно бросить
     на всю карточку. Нет фото — одна большая плитка «Добавить фото». Кадры машины без скрытых и парковочных, адреса свои
     (`/photos/{id}`), имя нейтральное; поток загрузки (`media-stream`) меняет блок целиком. --}}
@php
    $photos = \App\Http\Partner\MediaController::shown($offer)['photos'];
    $me = auth()->id();
    $main = $photos->first();
    $v = fn ($m) => $m->updated_at?->timestamp;
@endphp
<div id="partner-photos" class="partner-gallery" data-controller="photos" data-photos-url-value="/offers/{{ $offer->id }}/media" data-photos-any-value="true">
    @if ($main)
        <button type="button" class="partner-gallery-main" data-action="photos#open" aria-label="Открыть фото">
            <img src="/photos/{{ $main->id }}/w960?v={{ $v($main) }}" data-full="/photos/{{ $main->id }}?v={{ $v($main) }}" data-mid="/photos/{{ $main->id }}/w960?v={{ $v($main) }}" data-id="{{ $main->id }}" alt="">
            <span class="partner-gallery-count nums">{{ $photos->count() }} фото</span>
        </button>
        <div class="photo-row">
            <button type="button" class="photo-add" data-action="photos#pick" aria-label="Добавить фото"><x-ui.icon name="camera" class="size-6"/></button>
            <div class="photo-strip" data-photos-target="grid">
@foreach ($photos as $i => $media)
<div class="photo-cell photo-cell--acts" data-id="{{ $media->id }}" data-hidden="0">
<img src="/photos/{{ $media->id }}/w320?v={{ $v($media) }}" data-full="/photos/{{ $media->id }}?v={{ $v($media) }}" data-mid="/photos/{{ $media->id }}/w960?v={{ $v($media) }}" alt="" loading="lazy" data-action="click->photos#open">
@if ($i === 0)<span class="photo-star photo-star--main" aria-label="Главное">@include('components.ui.star')</span>@endif
@if ((int) $media->getCustomProperty('partner') === $me)
<div class="photo-actions"><button type="button" data-action="photos#act" data-act="delete" data-confirm="Удалить фото?" aria-label="Удалить"><span class="pa-icon"></span></button></div>
@endif
</div>
@endforeach
            </div>
        </div>
    @else
        <button type="button" class="partner-gallery-empty" data-action="photos#pick">
            <x-ui.icon name="camera" class="size-8"/>
            <span>Добавить фото</span>
        </button>
        <div class="photo-strip" data-photos-target="grid" hidden></div>
    @endif
    <input type="file" accept="image/*,.heic,.heif,.pdf" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload">
    <div hidden data-photos-target="progress">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
</div>
