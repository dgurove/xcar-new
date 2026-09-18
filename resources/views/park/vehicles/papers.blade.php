<div id="papers" class="flex flex-col">
    @foreach ($vehicle->papers() as $media)
        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}">
            @if ($kind = \App\Mail\Extraction\AttachmentClassifier::kindLabel($media->getCustomProperty('kind')))<span class="chip shrink-0">{{ $kind }}</span>@endif
            <form method="post" action="/cars/{{ $vehicle->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>
        </x-ui.file>
    @endforeach
</div>
