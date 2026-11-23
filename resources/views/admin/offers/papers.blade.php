{{-- Документы предложения (23.11.2026, владелец выбрал из макетов «⋯ справа с меню поверх»): строка целиком — открыть документ;
     под именем — кто видит: «только мы» серым или «видит Иван Петров» лаймом (`Media\ForManagers`; открытый видят менеджеры
     машины — `Offer::managedBy`, `fileViewers`: сделки, в том числе параллельной, и держатель гаража; исполнителю вывоза
     файлы открывает своё правило `Logistics\FileAccess`). Справа «⋯» (`x-ui.more`: на телефоне шторка снизу, на ПК поповер):
     тумблер «Показывать менеджеру» (тем, кто ведёт сделки, `canEditDeals`), «Распознать» (прочитать заново,
     `OfferPhotoController::scan`), «Переименовать» (поле прямо в строке, `file_rename_controller`), «Удалить» — не у документа
     парковки: его удаляют на парковке, это её запись о машине. --}}
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
        @php
            $own = ! \App\Park\Sale::parkOwned($media);
            $shows = $canAccess && ! $media->getCustomProperty('supplier_invoice');
            $open = \App\Media\ForManagers::is($media);
            $base = '/offers/'.$offer->number.'/media/'.$media->id;
        @endphp
        <x-ui.file :name="$docName" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}" data-scan-key="{{ $media->getCustomProperty('sha') }}">
            <span class="contents" data-controller="file-rename" data-file-rename-url-value="{{ $base }}" data-file-rename-name-value="{{ $docName }}">
                <x-ui.more :id="'paper-'.$media->id" label="Действия с документом" class="more-pill--bare" data-file-rename-target="edit">
                    @if ($shows)
                        <form method="post" action="{{ $base }}/managers">@csrf
                            <button class="menu-item" role="menuitemcheckbox" aria-checked="{{ $open ? 'true' : 'false' }}"><x-ui.icon name="user" class="size-5 shrink-0 text-ink-muted"/><span class="flex-1 whitespace-nowrap text-left">Показывать менеджеру</span><span @class(['menu-switch', 'is-on' => $open]) aria-hidden="true"></span></button>
                        </form>
                    @endif
                    {{-- «Распознать» — только то, что читалка умеет (PDF, картинка, Word/Excel, не больше предела): иначе ответ 422. --}}
                    @if (\App\Mail\Extraction\DocumentText::scannable(new \App\Mail\Scan\Paper($media)))
                        <form method="post" action="{{ $base }}/scan">@csrf
                            <button class="menu-item" role="menuitem"><x-ui.icon name="sparkles" class="size-5 text-ink-muted"/>Распознать</button>
                        </form>
                    @endif
                    <button type="button" class="menu-item" role="menuitem" data-action="file-rename#start"><x-ui.icon name="edit" class="size-5 text-ink-muted"/>Переименовать</button>
                    @if ($own)
                        <form method="post" action="{{ $base }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')
                            <button class="menu-item text-danger" role="menuitem"><x-ui.icon name="trash" class="size-5"/>Удалить</button>
                        </form>
                    @endif
                </x-ui.more>
                <button type="button" class="btn btn-ghost btn-s px-2 text-accent-text" data-file-rename-target="save" data-action="file-rename#save" aria-label="Сохранить имя" hidden><x-ui.icon name="check" class="size-5"/></button>
            </span>
            <x-slot:under>
                @if ($shows)<span @class(['paper-access', 'is-open' => $open])>{{ $open ? $seen : 'только мы' }}</span>@endif
            </x-slot:under>
        </x-ui.file>
    @endforeach
</div>
