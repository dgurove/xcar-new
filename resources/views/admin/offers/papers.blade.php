{{-- Документы предложения: переименовать и удалить. Видит ли документ менеджер сделки (`Media\ForManagers`) — подписью под
     именем, нажатие переключает «только мы» ⇄ «видит менеджер сделки» (23.11.2026, владелец: «либо отображаем менеджерам сделки
     документ, либо не отображаем»; другие менеджеры документов машины не видят вовсе). Открытый видят менеджеры машины
     (`Offer::managedBy`, `fileViewers`): сделки, в том числе параллельной, и держатель гаража; исполнителю вывоза файлы
     открывает своё правило (`Logistics\FileAccess`). Переключает тот, кто
     ведёт сделки (`canEditDeals`, маршрут в их группе). --}}
@php
    $canAccess = (bool) auth()->user()?->canEditDeals();
    // Кто видит открытый — именами (владелец: «не „менеджер сделки“, а конкретно имя»); сделки ещё нет — «видит менеджер сделки».
    $viewers = $canAccess ? $offer->fileViewers()->map(fn ($u) => $u->shortName())->sort()->values() : collect();
    $seen = match ($viewers->count()) {
        0 => 'видит менеджер сделки',
        1 => 'видит '.$viewers->first(),
        2 => 'видят '.$viewers->join(' и '),
        default => 'видят '.$viewers->first().' и ещё '.($viewers->count() - 1),
    };
@endphp
<div id="papers" class="flex flex-col">
    @foreach ($offer->papers() as $media)
        @php $docName = \App\Media\DocName::of($media); @endphp
        <x-ui.file :name="$docName" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            {{-- Переименовать — любой документ; документ парковки удаляют только на парковке (это её запись о машине). --}}
            <x-ui.file-rename :action="'/offers/'.$offer->number.'/media/'.$media->id" :name="$docName"/>
            @unless (\App\Park\Sale::parkOwned($media))<form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="x" class="size-5"/></button></form>@endunless
            <x-slot:under>
            @if ($canAccess && ! $media->getCustomProperty('supplier_invoice'))
                @php $open = \App\Media\ForManagers::is($media); @endphp
                <form method="post" action="/offers/{{ $offer->number }}/media/{{ $media->id }}/managers" class="contents">@csrf
                    <button type="submit" @class(['paper-access', 'is-open' => $open]) title="{{ $open ? 'Скрыть от менеджера' : 'Показать менеджеру' }}">{{ $open ? $seen : 'только мы' }}</button>
                </form>
            @endif
            </x-slot:under>
        </x-ui.file>
    @endforeach
</div>
