{{-- Значения источников поля правки выпадающим списком (08.11.2026, владелец: «при нажатии на поле — в дропдауне другие
     варианты»; блок под полем был «не то»): тем же видом, что список справочника, поверх формы под полем ввода. Первым — что
     стоит сейчас, с галкой; нажатие на другое ставит значение в поле, сохраняет обычное «Сохранить»
     (`source_values_controller`). Источник — `Values::known` (и отклонённые). items — [value, label, from, now]. --}}
@props(['items' => []])
@if ($items)
    <div class="combobox-list source-values" data-controller="source-values" data-action="keydown.esc->source-values#close" hidden>
        @foreach ($items as $i)
            <button type="button" @class(['combobox-option source-option', 'is-now' => $i['now']]) data-action="source-values#pick" data-source-values-value-param="{{ $i['value'] }}">
                <span class="source-value">{{ $i['label'] }}</span>
                <span class="source-from">{{ $i['from'] }}</span>
                <span class="source-check">@if ($i['now'])<x-ui.icon name="check" class="size-4"/>@endif</span>
            </button>
        @endforeach
    </div>
@endif
