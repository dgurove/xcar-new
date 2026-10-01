{{-- Группа «документы» над полями ТС: строка на расхождение карточки с документом (FillFromDocs::differences) —
     «в карточке → в документе», под ней поле и документ; строка и есть действие «Взять» с подтверждением: кнопка
     отправляет форму вокруг на свой адрес (вложенной формы быть не может). Последней строкой — слот: вход в окно
     «Из документов» (x-mail.scan-button). Пусто и то и другое — группы нет. --}}
@props(['vehicle', 'differences' => []])
@php $labels = ['vin' => 'VIN', 'plate' => 'Госномер', 'year' => 'Год', 'color' => 'Цвет', 'value' => 'Стоимость', 'model' => 'Модель']; @endphp
@if ($differences || $slot->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'list doc-diff']) }}>
        @foreach ($differences as $field => $d)
            @php
                $now = $field === 'model' ? $vehicle->model?->name : $vehicle->{$field};
                $nums = in_array($field, ['vin', 'year', 'value'], true) ? 'nums' : '';
                // Стоимость — деньгами, как в окне «Распознать»; в форму уходит голое значение документа.
                $show = fn ($v) => $field === 'value' && is_numeric($v) ? \App\Support\Money::rub((int) $v) : $v;
            @endphp
            <button type="submit" class="row scan-field text-left" formaction="/cars/{{ $vehicle->id }}/take" formmethod="post" formnovalidate name="take" value="{{ $field }}|{{ $d['value'] }}"
                data-turbo-confirm="{{ $labels[$field] }} из документа: {{ $show($d['value']) }}?" data-turbo-confirm-label="Взять">
                <span class="scan-label">{{ $labels[$field] }}</span>
                <span class="min-w-0 flex-1">
                    <span class="scan-value scan-change"><span class="text-ink-muted {{ $nums }}">{{ $show($now) }}</span><span class="text-ink-dim" aria-hidden="true">→</span><span class="{{ $nums }}">{{ $show($d['value']) }}</span></span>
                    <span class="scan-from">{{ implode(', ', $d['sources']) }}</span>
                </span>
                <span class="shrink-0 self-center text-sm text-accent-text">Взять</span>
            </button>
        @endforeach
        {{ $slot }}
    </div>
@endif
