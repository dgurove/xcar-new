{{-- Одно письмо страховой подложкой: дата, слова до подписи, файлы письма строками (шторка документов). --}}
<div class="reply">
    <p class="nums text-sm text-ink-dim">{{ $reply->date_at?->translatedFormat('j M, H:i') }}</p>
    <p class="mt-1 whitespace-pre-line break-words">{!! \App\Support\Linkify::html($reply->replyText()) !!}</p>
    @if (($files = ($replyFiles ?? collect())->get($reply->id, [])) && count($files))
        <div class="mt-2 flex flex-col">
            @foreach ($files as $media)
                <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}"/>
            @endforeach
        </div>
    @endif
</div>
