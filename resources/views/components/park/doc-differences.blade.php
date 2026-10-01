{{-- Карточка ТС и её документы расходятся (FillFromDocs::differences): строка на поле — «в карточке → в документе»,
     под ней поле и документ. Строка и есть действие «Взять» с подтверждением: кнопка отправляет форму дела вокруг
     на свой адрес (вложенной формы быть не может). --}}
@props(['vehicle', 'differences' => []])
@php $labels = ['vin' => 'VIN', 'plate' => 'Госномер', 'year' => 'Год', 'color' => 'Цвет', 'value' => 'Стоимость', 'model' => 'Модель']; @endphp
@if ($differences)
    <div {{ $attributes->merge(['class' => 'list doc-diff']) }}>
        @foreach ($differences as $field => $d)
            @php $now = $field === 'model' ? $vehicle->model?->name : $vehicle->{$field}; $nums = in_array($field, ['vin', 'year', 'value'], true) ? 'nums' : ''; @endphp
            <button type="submit" class="row text-left" formaction="/cars/{{ $vehicle->id }}/take" formmethod="post" formnovalidate name="take" value="{{ $field }}|{{ $d['value'] }}"
                data-turbo-confirm="{{ $labels[$field] }} из документа: {{ $d['value'] }}?" data-turbo-confirm-label="Взять">
                <span class="min-w-0 flex-1">
                    <span class="scan-value scan-change"><span class="text-ink-muted {{ $nums }}">{{ $now }}</span><span class="text-ink-dim" aria-hidden="true">→</span><span class="{{ $nums }}">{{ $d['value'] }}</span></span>
                    <span class="row-sub"><span>{{ $labels[$field] }}</span><span>{{ implode(', ', $d['sources']) }}</span></span>
                </span>
                <span class="shrink-0 text-sm text-accent-text">Взять</span>
            </button>
        @endforeach
    </div>
@endif
