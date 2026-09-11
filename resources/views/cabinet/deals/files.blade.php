<div id="requirement-files" class="flex flex-col gap-1">
    @foreach ($requirement->getMedia('files') as $media)
        <div class="flex items-center gap-3 py-1.5">
            <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
            <a href="{{ $media->getUrl() }}" target="_blank" class="flex-1 truncate">{{ $media->file_name }}</a>
            <span class="text-sm text-ink-muted">{{ $media->humanReadableSize }}</span>
            <form method="post" action="/lk/sdelki/{{ $requirement->deal_id }}/fayly/{{ $media->id }}">@csrf @method('delete')<button class="btn btn-ghost btn-sm px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
        </div>
    @endforeach
</div>
