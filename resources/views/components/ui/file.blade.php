{{-- Файл строкой: значок типа с весом внутри (`bytes`), имя одной строкой на всё свободное место — «…» только у правда
     длинного (13.11.2026, владелец: «не надо кучу места другими элементами отнимать»: веса справа нет, значок меньше, кнопки
     вплотную), справа действия из слота одной группой (`.file-acts`). size — подпись строкой под именем («от 10.11»). Нажатие открывает шторку документов (x-ui.docs); auto — шторка открывает его сама,
     как только страница показана. --}}
@props(['name', 'href' => null, 'size' => null, 'bytes' => null, 'mime' => null, 'download' => false, 'auto' => false, 'refs' => []])
@php
    // На xcar в имени файла нет номера убытка и ДЛ (`InternalRefs`); refs — номера этой машины
    // в той форме, что в карточке; тип файла — по настоящему имени.
    $shown = \App\Support\Surface::current() === \App\Support\Surface::Site ? \App\Support\InternalRefs::fileName($name, $refs) : $name;
@endphp
<div {{ $attributes->merge(['class' => 'file-row']) }}>
    @if ($href)
        <a href="{{ $href }}" target="_blank" class="contents" data-doc="{{ \App\Support\Docs::type($mime, $name) }}" data-doc-name="{{ \App\Support\Docs::label($shown) }}" title="{{ $shown }}" @if ($auto) data-doc-auto @endif @if (str_starts_with($href, '/files/') && preg_match('/\.hei[cf]$/i', $name)) data-doc-src="{{ $href }}?jpeg=1" @endif>
            <x-ui.file-icon :name="$name" :mime="$mime" :bytes="$bytes"/>
            <span class="file-name"><span data-file-name>{{ $shown }}</span>@if ($size)<span class="file-sub">{{ $size }}</span>@endif</span>
        </a>
    @else
        <x-ui.file-icon :name="$name" :mime="$mime" :bytes="$bytes"/>
        <span class="file-name"><span data-file-name>{{ $shown }}</span>@if ($size)<span class="file-sub">{{ $size }}</span>@endif</span>
    @endif
    @if (($download && $href) || $slot->isNotEmpty())
        <span class="file-acts">
            @if ($download && $href)<a href="{{ $href }}" download class="btn btn-ghost btn-s px-1.5 text-ink-muted" aria-label="Скачать" data-controller="file" data-action="file#share"><x-ui.icon name="download" class="size-5"/></a>@endif
            {{ $slot }}
        </span>
    @endif
</div>
