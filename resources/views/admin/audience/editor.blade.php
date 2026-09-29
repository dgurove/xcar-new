{{-- Начинка шторки «Кому»: список «кто → когда» (строки рисует audience_controller), «+ Группа или менеджер» —
     системный выбор под кнопкой, ниже чипы шаблонов (у шаблона в настройках их нет). --}}
<div class="list" data-audience-target="list"></div>
<label class="add-select pill pill-plain mt-3">
    <x-ui.icon name="plus" class="size-4"/> Группа или менеджер
    <select data-audience-target="add" data-action="audience#add" aria-label="Добавить группу или менеджера"></select>
</label>
@if ($audienceOptions['presets'] ?? [])
    <div class="mt-5 flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-sm text-ink-muted">Шаблоны</span>
        @foreach ($audienceOptions['presets'] as $preset)
            <button type="button" class="pill pill-plain" data-audience-target="preset" data-action="audience#preset" data-audience-id-param="{{ $preset['id'] }}" aria-pressed="false">{{ $preset['name'] }}</button>
        @endforeach
    </div>
@endif
