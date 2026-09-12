<div id="gallery" class="photo-grid" data-photos-target="grid">
    @foreach ($car->photos() as $media)
        @php $hidden = $media->getCustomProperty('hidden', false); @endphp
        <div class="photo-cell {{ $hidden ? 'is-hidden' : '' }}" data-id="{{ $media->id }}">
            <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" loading="lazy">
            @if ($loop->first && !$hidden)<span class="photo-main">Главное</span>@endif
            <div class="photo-actions">
                <form method="post" action="/zakupki/mashiny/{{ $car->id }}/media/{{ $media->id }}/glavnoe">@csrf<button aria-label="Сделать главным"><x-ui.icon name="check" class="size-4"/></button></form>
                <form method="post" action="/zakupki/mashiny/{{ $car->id }}/media/{{ $media->id }}/skryt">@csrf<button aria-label="Скрыть"><x-ui.icon name="{{ $hidden ? 'sun' : 'moon' }}" class="size-4"/></button></form>
                <form method="post" action="/zakupki/mashiny/{{ $car->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить фото?">@csrf @method('delete')<button aria-label="Удалить"><x-ui.icon name="trash" class="size-4"/></button></form>
            </div>
        </div>
    @endforeach
</div>
