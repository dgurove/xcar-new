{{-- Файлы раскрытого письма (17.11.2026, владелец выбрал из макетов): «N документов и M фото» и «Скачать все» (архивом),
     документы — компактными карточками в две колонки (значок типа с весом, имя целиком), фото — одной карточкой «N фото» без
     миниатюр: просмотр грузит кадр, когда до него долистали («фотки почти юзлесс, они и так уходят в предложение»). Архив с
     записями — кадрами и документами внутри (`x-mail.archive`). Старое письмо (файлы не скачаны, `filesFrozen`) — карточки
     без веса, нажатие достаёт файл из ящика. --}}
@props(['message', 'base'])
@php
    $m = $message;
    $files = $m->files();
    [$pictures, $documents] = $files->partition(fn ($f) => $f->isImage() && $f->mime !== 'image/svg+xml');
    $archives = $documents->filter(fn ($f) => \App\Support\Docs::archive($f));
    $documents = $documents->diff($archives);
    $docs = $documents->count() + $archives->count();
    $frozen = $m->filesFrozen();
@endphp
@if ($files->isNotEmpty())
    <div class="letter-files">
        <div class="letter-files-head">
            <span>{{ $docs ? $docs.' '.\App\Support\Plural::of($docs, ['документ', 'документа', 'документов']) : '' }}{{ $docs && $pictures->isNotEmpty() ? ' и ' : '' }}{{ $pictures->isNotEmpty() ? $pictures->count().' фото' : '' }}</span>
            @unless ($frozen)<a href="{{ $base }}/messages/{{ $m->id }}/files.zip" class="letter-files-all" download data-turbo="false">Скачать все</a>@endunless
        </div>
        <div class="letter-docs">
            @foreach ($frozen ? $files : $documents as $file)
                <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="letter-doc" data-doc="{{ \App\Support\Docs::type($file->mime, $file->filename) }}" data-doc-name="{{ \App\Support\Docs::label($file->filename) }}" title="{{ $file->filename }}">
                    <x-ui.file-icon :name="$file->filename" :mime="$file->mime" :bytes="$frozen ? null : $file->size"/><span class="min-w-0 truncate">{{ $file->filename }}</span>
                </a>
            @endforeach
            @if (! $frozen && $pictures->isNotEmpty())
                <div class="contents" data-controller="photos" data-photos-readonly-value="true">
                    <button type="button" class="letter-doc" data-action="click->photos#open">
                        <span class="letter-photos-icon"><x-ui.icon name="photo" class="size-5"/></span><span class="min-w-0 flex-1 truncate text-left">{{ $pictures->count() }} фото</span><x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-muted"/>
                    </button>
                    @foreach ($pictures as $file)<img data-full="{{ $base }}/attachments/{{ $file->id }}?large=1" data-download="{{ $base }}/attachments/{{ $file->id }}" alt="" hidden>@endforeach
                </div>
            @endif
        </div>
        @unless ($frozen)
            @foreach ($archives as $file)<x-mail.archive :file="$file" :entries="\App\Support\Docs::archive($file)" :base="$base"/>@endforeach
        @endunless
    </div>
@endif
