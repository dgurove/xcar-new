{{-- Документ для шторки (x-ui.docs): ссылка с типом, подписью и данными вида (`Support\Docs`). Нажатие открывает
     шторку; без JS — файл в новой вкладке. auto — шторка открывает его сама, как только страница показана. --}}
@props(['doc', 'auto' => false])
@php $files = ! in_array($doc['type'], ['letter', 'photos'], true); @endphp
<a href="{{ $doc['url'] }}" data-doc="{{ $doc['type'] }}" data-doc-name="{{ $doc['label'] ?? $doc['name'] }}" title="{{ $doc['name'] }}"
    @if (! empty($doc['src'])) data-doc-src="{{ $doc['src'] }}" @endif
    @if (! empty($doc['photos'])) data-doc-photos="{{ json_encode($doc['photos']) }}" @endif
    @if (! empty($doc['thread'])) data-doc-thread="{{ $doc['thread'] }}" @endif
    @if ($auto) data-doc-auto @endif
    @if ($files) target="_blank" @else data-turbo="false" @endif
    {{ $attributes }}>{{ $slot->isEmpty() ? ($doc['label'] ?? $doc['name']) : $slot }}</a>
