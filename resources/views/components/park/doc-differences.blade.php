{{-- Группа «документы» над полями ТС: строка на расхождение карточки с документом (FillFromDocs::differences) —
     поле, «в карточке → в документе», откуда, под ними «Отклонить» и «Взять» с подтверждением: кнопки
     отправляют форму вокруг на свой адрес (вложенной формы быть не может). Последней строкой — слот: вход в окно
     «Из документов» (x-mail.scan-button). Пусто и то и другое — группы нет. bare — строками чужой группы `.list` (окошко
     строки, 20.10.2026), form — id внешней формы `/take`: в группе есть шторки со своими формами, вложить её нельзя. --}}
@props(['vehicle', 'differences' => [], 'bare' => false, 'form' => null])
@php $labels = ['vin' => 'VIN', 'plate' => 'Госномер', 'year' => 'Год', 'color' => 'Цвет', 'value' => 'Оценочная стоимость', 'model' => 'Модель']; @endphp
@if ($differences || $slot->isNotEmpty())
    @unless ($bare)<div {{ $attributes->merge(['class' => 'list doc-diff']) }}>@endunless
        @foreach ($differences as $field => $d)
            @php
                $now = $field === 'model' ? $vehicle->model?->name : $vehicle->{$field};
                $nums = in_array($field, ['vin', 'year', 'value'], true) ? 'nums' : '';
                // Стоимость — деньгами, как в окне «Распознать»; в форму уходит голое значение документа.
                $show = fn ($v) => $field === 'value' && is_numeric($v) ? \App\Support\Money::rub((int) $v) : $v;
            @endphp
            {{-- Строкой в столбик (06.11.2026, владелец: подпись, «было → стало», откуда и две кнопки в одну линию — «поооолная
                 жесть»): что за поле, было → стало (переносится целыми значениями), откуда, ниже кнопки. Одна раскладка на
                 любой ширине — окошко строки узкое и на ПК. --}}
            <div class="row doc-diff-row">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm text-ink-muted">{{ $labels[$field] }}</span>
                    <span class="doc-diff-change"><span class="text-ink-muted {{ $nums }}">{{ $show($now) }}</span><span class="text-ink-dim" aria-hidden="true">→</span><span class="{{ $nums }}">{{ $show($d['value']) }}</span></span>
                    <span class="block text-sm text-ink-dim">{{ implode(', ', $d['sources']) }}</span>
                    <span class="mt-2 flex gap-2">
                        <button type="submit" @if ($form) form="{{ $form }}" @endif class="btn btn-s btn-quiet flex-1 max-w-48" formaction="/cars/{{ $vehicle->id }}/take" formmethod="post" formnovalidate name="reject" value="{{ $field }}|{{ $d['value'] }}">Отклонить</button>
                        <button type="submit" @if ($form) form="{{ $form }}" @endif class="btn btn-s btn-accent flex-1 max-w-48" formaction="/cars/{{ $vehicle->id }}/take" formmethod="post" formnovalidate name="take" value="{{ $field }}|{{ $d['value'] }}"
                            data-turbo-confirm="{{ $labels[$field] }} из документа: {{ $show($d['value']) }}?" data-turbo-confirm-label="Взять">Взять</button>
                    </span>
                </span>
            </div>
        @endforeach
        {{ $slot }}
    @unless ($bare)</div>@endunless
@endif
