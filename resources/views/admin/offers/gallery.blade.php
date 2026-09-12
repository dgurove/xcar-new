<div id="gallery">
    @if ($offer->photos()->isNotEmpty())
        <div class="photo-grid" data-photos-target="grid">
            @foreach ($offer->photos() as $i => $media)
                @php $hidden = $media->getCustomProperty('hidden', false); @endphp
                <div class="photo-cell {{ $hidden ? 'is-hidden' : '' }}" data-id="{{ $media->id }}">
                    <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" loading="lazy">
                    @if ($i === 0 && !$hidden)<span class="photo-main">Главное</span>@endif
                    <div class="photo-actions">
                        <form method="post" action="/predlozheniya/{{ $offer->number }}/media/{{ $media->id }}/skryt">@csrf<button aria-label="{{ $hidden ? 'Показать' : 'Скрыть' }}"><x-ui.icon name="{{ $hidden ? 'photo' : 'x' }}" class="size-4"/></button></form>
                        @if ($i !== 0)<form method="post" action="/predlozheniya/{{ $offer->number }}/media/{{ $media->id }}/glavnoe">@csrf<button aria-label="Сделать главным"><x-ui.icon name="check" class="size-4"/></button></form>@endif
                        <form method="post" action="/predlozheniya/{{ $offer->number }}/media/{{ $media->id }}" data-turbo-confirm="Удалить фото?">@csrf @method('delete')<button aria-label="Удалить"><x-ui.icon name="trash" class="size-4"/></button></form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
