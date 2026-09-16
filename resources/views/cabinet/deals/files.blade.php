<div id="requirement-files" class="flex flex-col">
    @foreach ($requirement->getMedia('files') as $media)
        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}">
            <form method="post" action="/account/deals/{{ $requirement->deal_id }}/files/{{ $media->id }}">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button></form>
        </x-ui.file>
    @endforeach
</div>
