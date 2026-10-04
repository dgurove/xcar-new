{{-- Документы предложения. Админу у каждого — «Видно менеджеру» (`Media\ForManagers`): без отметки документ видят только
     сотрудники, с ней — держатель гаража, вывозчик и менеджер сделки. --}}
@php $admin = auth()->user()?->canManageCrm(); @endphp
<div id="papers" class="flex flex-col">
    @foreach ($offer->papers() as $media)
        <x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            @if ($admin)
                @php $open = \App\Media\ForManagers::is($media); @endphp
                <form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}/managers" class="contents">@csrf
                    @if ($open)
                        <button class="tag tag-accent shrink-0 gap-1" title="Закрыть от менеджеров"><x-ui.icon name="users" class="size-3.5"/>Видно менеджеру</button>
                    @else
                        <button class="btn btn-ghost btn-s px-2 text-ink-dim" aria-label="Открыть менеджеру" title="Открыть менеджеру"><x-ui.icon name="users" class="size-5"/></button>
                    @endif
                </form>
            @endif
            {{-- Документ парковки удаляют на парковке. --}}
            @unless (\App\Park\Sale::parkOwned($media))<form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>@endunless
        </x-ui.file>
    @endforeach
</div>
