{{-- Документы предложения: «Добавить документ», полоса загрузки и список файлов (удалить — у файла). Один кусок на
     редактор и карточку строки. Документы читаются сами (`Cars\Jobs\ReadCarDocs`, 07.11.2026): что в них о машине —
     вариантами полей в блоках машины (`x-car.variants`), пустое встаёт само. --}}
<div data-controller="photos" data-photos-url-value="/offers/{{ $offer->number }}/media" data-photos-collection-value="papers">
    {{-- Выбор файла — подписью у самого поля, а не щелчком скрипта по скрытому: так iOS открывает его всегда
         (07.10.2026, владелец: «+ Документ не нажимается»). Поле невидимо, но не display: none — иначе подпись его не зовёт. --}}
    <div class="mb-2 flex items-center gap-2"><label class="btn btn-quiet btn-s relative cursor-pointer"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload"><x-ui.icon name="plus" class="size-4"/> Добавить документ</label></div>
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
    @include('admin.offers.papers')
</div>
