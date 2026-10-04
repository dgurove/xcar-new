<div id="papers" class="flex flex-col">
    @foreach ($offer->papers() as $media)
        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Документ парковки удаляют на парковке. --}}
            @unless (\App\Park\Sale::parkOwned($media))<form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>@endunless
        </x-ui.file>
    @endforeach
</div>
