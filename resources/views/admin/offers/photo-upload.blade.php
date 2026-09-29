{{-- Выбор файлов и полоса загрузки кадров — внутри data-controller="photos" (редактор и окошко строки). --}}
<input type="file" accept="image/*,.heic,.heif" multiple hidden data-photos-target="input" data-action="change->photos#upload">
<div hidden data-photos-target="progress" class="mb-3">
    <div class="mb-1 text-sm text-ink-muted" data-label></div>
    <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
</div>
