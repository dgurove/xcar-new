{{-- Документы машины чипами одной строкой с прокруткой вбок (12.11.2026, владелец: «реальные чипы документов: если pdf — то pdf,
     цвет и имя, все компактно в одну строку»): значок типа своим цветом (`x-ui.file-icon`) и имя; нажатие открывает шторку
     документов (`x-ui.doc`) на этом файле. Письмо и фото из набора — скрытыми ссылками: шторка листает все вкладки по порядку
     ссылок. Файлов нет (только письмо) — прежней пилюлей `x-ui.docs-pill`. Окно машины CRM. --}}
@props(['docs'])
@php $files = \App\Support\Docs::files($docs ?: null); @endphp
@if ($files->isEmpty())
    <x-ui.docs-pill :docs="$docs"/>
@else
    <span hidden>@foreach ($docs as $doc)@unless ($files->contains($doc))<x-ui.doc :doc="$doc"/>@endunless{{ "" }}@endforeach</span>
    <div {{ $attributes->class('docs-chips') }}>
        @foreach ($files as $doc)
            <x-ui.doc :doc="$doc" class="doc-chip"><x-ui.file-icon :name="$doc['name']" class="file-icon-s"/><span class="truncate">{{ $doc['label'] ?? $doc['name'] }}</span></x-ui.doc>
        @endforeach
    </div>
@endif
