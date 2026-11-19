{{-- Скачать договор — выбор формата (19.11.2026, владелец: «когда на кнопку скачать у договора нажимают, что в CRM, что в
     админке, выскакивало окошко, где спрашивалось: в docx скачать или в pdf»): значок скачивания открывает `x-ui.sheet anchor` —
     на телефоне шторкой как в iOS, на ПК поповером у кнопки; пункты «Word (.docx)» и «PDF» — тем же `file#share`, что у
     остальных документов. Исправленный приложили PDF-ом — Word нет (`ContractEdit::hasWord`). `base` — `/work/deals/{id}` в CRM,
     `/deals/{id}` у менеджера. --}}
@props(['deal', 'base', 'name', 'word' => true])
@php $id = 'dkp-dl-'.$deal->id.'-'.substr(md5($base), 0, 6); @endphp
<span class="contents" data-controller="sheet">
    <button type="button" class="btn btn-ghost btn-s px-1.5 text-ink-muted" data-action="sheet#open" aria-label="Скачать" title="Скачать" aria-haspopup="dialog" aria-controls="{{ $id }}"><x-ui.icon name="download" class="size-5"/></button>
    <x-ui.sheet :id="$id" anchor bare class="sheet-actions">
        <div class="sheet-actions-list" data-action="click->sheet#item">
            <div class="menu-group">
                @if ($word)
                    <a href="{{ $base }}/dkp.docx" download class="menu-item" role="menuitem" data-turbo="false" data-turbo-prefetch="false" data-controller="file" data-action="file#share" data-file-name="{{ $name }}.docx"><x-ui.file-icon name="dkp.docx" class="file-icon-s"/>Word (.docx)</a>
                @endif
                <a href="{{ $base }}/dkp.pdf" download class="menu-item" role="menuitem" data-turbo="false" data-turbo-prefetch="false" data-controller="file" data-action="file#share" data-file-name="{{ $name }}.pdf"><x-ui.file-icon name="dkp.pdf" class="file-icon-s"/>PDF</a>
            </div>
        </div>
        <button type="button" class="sheet-actions-cancel" data-action="sheet#close">Отмена</button>
    </x-ui.sheet>
</span>
