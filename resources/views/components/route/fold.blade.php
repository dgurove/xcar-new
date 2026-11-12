{{-- Свёрнутый путь окна машины (`x-route.path compact`, 12.11.2026, владелец: «снизу куча навалено»): виден текущий шаг,
     строка «Весь путь N» раскрывает пройденные и впереди на месте, «Свернуть» — обратно. Без скрипта — флажок и CSS
     (`.steps--compact` в `app.css`); стоит последним в `.steps`. --}}
@props(['count' => 0])
<label class="steps-all">
    <input type="checkbox" class="steps-all-check">
    <span class="steps-all-open">Весь путь@if ($count > 1) <span class="nums text-ink-dim">{{ $count }}</span>@endif</span>
    <span class="steps-all-close">Свернуть</span>
    <x-ui.icon name="chevron-down" class="steps-all-chevron size-4"/>
</label>
