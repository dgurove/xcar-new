{{-- Фото черновика у поставщика: ряд кадров машины (без скрытых и парковочных), адреса — свои (`/photos/{id}`), имя — нейтральное.
     Добавить, порядок и звезда — как в CRM (photos_controller); корзина — только у своих кадров. --}}
@php $photos = \App\Http\Partner\MediaController::shown($offer)['photos']; $me = auth()->id(); @endphp
<div id="partner-photos" data-controller="photos" data-photos-url-value="/offers/{{ $offer->id }}/media" data-photos-any-value="true">
    <div class="photo-row">
        <button type="button" class="photo-add" data-action="photos#pick" aria-label="Добавить фото"><x-ui.icon name="camera" class="size-6"/></button>
        <div class="photo-strip" data-photos-target="grid">
@foreach ($photos as $i => $media)
<div class="photo-cell photo-cell--acts" data-id="{{ $media->id }}" data-hidden="0">
<img src="/photos/{{ $media->id }}/w320?v={{ $media->updated_at?->timestamp }}" data-full="/photos/{{ $media->id }}?v={{ $media->updated_at?->timestamp }}" data-mid="/photos/{{ $media->id }}/w960?v={{ $media->updated_at?->timestamp }}" alt="" loading="lazy" data-action="click->photos#open">
@if ($i === 0)<span class="photo-star photo-star--main" aria-label="Главное">@include('components.ui.star')</span>@endif
@if ((int) $media->getCustomProperty('partner') === $me)
<div class="photo-actions"><button type="button" data-action="photos#act" data-act="delete" data-confirm="Удалить фото?" aria-label="Удалить"><span class="pa-icon"></span></button></div>
@endif
</div>
@endforeach
        </div>
    </div>
    <input type="file" accept="image/*,.heic,.heif,.pdf" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload">
    <div hidden data-photos-target="progress" class="mt-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
</div>
