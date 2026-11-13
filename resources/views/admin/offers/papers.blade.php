{{-- Документы предложения: переименовать и удалить. Что из них видно менеджеру (`Media\ForManagers`), решают в «Что
     показываем» — кнопки с человечками у строки нет (13.11.2026, владелец: «уже и так есть»). --}}
<div id="papers" class="flex flex-col">
    @foreach ($offer->papers() as $media)
        @php $docName = \App\Media\DocName::of($media); @endphp
        <x-ui.file :name="$docName" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Документ парковки переименовывают и удаляют на парковке. --}}
            @unless (\App\Park\Sale::parkOwned($media))<x-ui.file-rename :action="'/offers/'.$offer->number.'/media/'.$media->id" :name="$docName"/>@endunless
            @unless (\App\Park\Sale::parkOwned($media))<form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="x" class="size-5"/></button></form>@endunless
        </x-ui.file>
    @endforeach
</div>
