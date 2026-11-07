{{-- Значения источников под полем правки (08.11.2026, владелец: «выбрали вариант — остальные не стирать, чтобы при правке
     поля можно было выбрать другой»): при фокусе в поле — строки «значение — откуда» тем же видом, что подсказки
     справочника; нажатие ставит значение в поле, сохраняет обычное «Сохранить» (`source_values_controller`). Источник —
     `Values::known` (и отклонённые); то, что уже в поле, не показывается. items — [value, label, from]. --}}
@props(['items' => []])
@if ($items)
    <div class="source-values" data-controller="source-values" hidden>
        @foreach ($items as $i)
            <button type="button" class="combobox-option combobox-option-stack" data-action="source-values#pick" data-source-values-value-param="{{ $i['value'] }}">
                <span>{{ $i['label'] }}</span><span class="text-sm text-ink-muted">{{ $i['from'] }}</span>
            </button>
        @endforeach
    </div>
@endif
