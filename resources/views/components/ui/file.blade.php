{{-- Файл строкой: иконка типа, имя, размер, справа действия из слота. Нажатие открывает шторку документов (x-ui.docs);
     auto — шторка открывает его сама, как только страница показана. --}}
@props(['name', 'href' => null, 'size' => null, 'mime' => null, 'thumb' => null, 'download' => false, 'auto' => false, 'refs' => []])
@php
    // На xcar в имени файла нет номера убытка и ДЛ (`InternalRefs`); refs — номера этой машины
    // в той форме, что в карточке; тип файла — по настоящему имени.
    $shown = \App\Support\Surface::current() === \App\Support\Surface::Site ? \App\Support\InternalRefs::fileName($name, $refs) : $name;
@endphp
<div {{ $attributes->merge(['class' => 'file-row']) }}>
    @if ($href)
        <a href="{{ $href }}" target="_blank" class="contents" data-doc="{{ \App\Support\Docs::type($mime, $name) }}" data-doc-name="{{ \App\Support\Docs::label($shown) }}" title="{{ $shown }}" @if ($auto) data-doc-auto @endif @if (str_starts_with($href, '/files/') && preg_match('/\.hei[cf]$/i', $name)) data-doc-src="{{ $href }}?jpeg=1" @endif>
            <x-ui.file-icon :name="$name" :mime="$mime" :thumb="$thumb"/>
            <span class="min-w-0 flex-1 truncate">{{ $shown }}</span>
        </a>
    @else
        <x-ui.file-icon :name="$name" :mime="$mime" :thumb="$thumb"/>
        <span class="min-w-0 flex-1 truncate">{{ $shown }}</span>
    @endif
    @if ($size)<span class="shrink-0 text-sm text-ink-muted">{{ $size }}</span>@endif
    @if ($download && $href)<a href="{{ $href }}" download class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Скачать" data-controller="file" data-action="file#share"><x-ui.icon name="download" class="size-5"/></a>@endif
    {{ $slot }}
</div>
