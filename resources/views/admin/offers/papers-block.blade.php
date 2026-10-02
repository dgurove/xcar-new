{{-- Документы предложения: «Добавить документ» и рядом искра «Из документов», полоса загрузки и список файлов (удалить — у файла). Один кусок на редактор
     и окошко строки. --}}
<div data-controller="photos" data-photos-url-value="/offers/{{ $offer->number }}/media" data-photos-collection-value="papers">
    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple hidden data-photos-target="input" data-action="change->photos#upload">
    <div class="mb-2 flex items-center gap-2"><x-ui.button type="button" variant="secondary" size="sm" data-action="photos#pick"><x-ui.icon name="plus" class="size-4"/> Добавить документ</x-ui.button>@include('admin.offers.scan-row')</div>
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
    @include('admin.offers.papers')
</div>
