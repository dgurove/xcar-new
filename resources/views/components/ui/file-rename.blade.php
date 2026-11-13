{{-- Карандаш у документа (13.11.2026, Сергей с парковки: «помимо крестика добавить карандашик, чтобы удобно переименовать
     прямо в системе»): нажатие — имя в строке (`x-ui.file`, `[data-file-name]`) становится полем, Enter или «✓» — сохранить,
     Esc — отмена. Без своей формы (строка бывает внутри формы отчёта): PATCH `action` с `name` (`file_rename_controller`),
     ответ — новое имя (`Media\DocName`). --}}
@props(['action', 'name'])
<span class="contents" data-controller="file-rename" data-file-rename-url-value="{{ $action }}" data-file-rename-name-value="{{ $name }}">
    <button type="button" class="btn btn-ghost btn-s px-2 text-ink-muted" data-file-rename-target="edit" data-action="file-rename#start" aria-label="Переименовать" title="Переименовать"><x-ui.icon name="edit" class="size-5"/></button>
    <button type="button" class="btn btn-ghost btn-s px-2 text-accent-text" data-file-rename-target="save" data-action="file-rename#save" aria-label="Сохранить имя" hidden><x-ui.icon name="check" class="size-5"/></button>
</span>
