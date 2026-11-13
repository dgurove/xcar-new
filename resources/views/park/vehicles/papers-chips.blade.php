{{-- Документы окна машины парковки чипами одной строкой с прокруткой вбок (12.11.2026, владелец: «реальные чипы документов… в
     окне»): значок типа цветом и имя, нажатие — шторка документов (`data-doc`, как у `x-ui.file`); последним — «+ Документ».
     Ответ загрузки (`papers-stream`) подменяет и его (`#papers-chips`). Удалить — в деле, где список строками. --}}
<div id="papers-chips" class="docs-chips">
    @foreach ($vehicle->papers() as $media)
        @php $name = \App\Media\DocName::of($media); @endphp
        <a href="/files/{{ $media->id }}" target="_blank" class="doc-chip" data-doc="{{ \App\Support\Docs::type($media->mime_type, $name) }}" data-doc-name="{{ \App\Support\Docs::label($name) }}" title="{{ $name }}"><x-ui.file-icon :name="$name" :mime="$media->mime_type" class="file-icon-s"/><span class="truncate">{{ \App\Support\Docs::label($name) }}</span></a>
    @endforeach
    <button type="button" class="doc-chip doc-chip--add" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/>Документ</button>
</div>
