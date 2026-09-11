<div id="gallery" class="photo-grid" data-photos-target="grid">
    @foreach ($vehicle->photos() as $media)
        <div class="photo-cell" data-id="{{ $media->id }}">
            <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" loading="lazy">
            <div class="photo-actions justify-end">
                <form method="post" action="/mashiny/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить фото?">@csrf @method('delete')<button aria-label="Удалить"><x-ui.icon name="trash" class="size-4"/></button></form>
            </div>
        </div>
    @endforeach
</div>
