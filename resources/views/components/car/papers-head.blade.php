{{-- Шапка «Документы» — одна для CRM, окна машины и парковки (23.11.2026, владелец: «единые модули»): название слева, справа
     «+ Добавить» лаймовым словом (как «Изменить» у карточек); поле файла — внутри метки (так iOS открывает выбор всегда, 07.10.2026),
     под шапкой — полоса загрузки. Живёт внутри `data-controller="photos"` с `collection=papers`. `section` — в окне (подпись 13). --}}
@props(['section' => false])
<div class="letters-head letters-head--acts">
    <h2 @class(['!text-sm !font-medium' => $section])>Документы</h2>
    <div class="letters-acts"><label class="letters-act relative cursor-pointer"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload"><x-ui.icon name="plus" class="size-4"/>Добавить</label></div>
</div>
<div hidden data-photos-target="progress" class="mb-3">
    <div class="mb-1 text-sm text-ink-muted" data-label></div>
    <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
</div>
