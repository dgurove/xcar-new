<div id="papers" class="flex flex-col gap-1">
    @foreach ($vehicle->papers() as $media)
        <div class="flex items-center gap-3 py-1.5">
            <a href="{{ $media->getUrl() }}" target="_blank" class="flex-1 truncate">{{ $media->file_name }}</a>
            <span class="text-sm text-ink-muted">{{ $media->humanReadableSize }}</span>
            <form method="post" action="/mashiny/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>
        </div>
    @endforeach
</div>
