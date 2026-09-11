{{-- Поле этапа по описанию {key,label,type}: для нас на входе и для менеджера в ответе. --}}
@props(['field', 'name', 'value' => null])
@php $id = 'rf-'.preg_replace('/\W+/', '-', $name); $bound = old($name, $value); $error = $errors->first($name); @endphp
<div class="field {{ $error ? 'field-invalid' : '' }}">
    <label for="{{ $id }}" class="field-label">{{ $field['label'] }}</label>
    @if (($field['type'] ?? 'text') === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" class="field-input">{{ $bound }}</textarea>
    @elseif ($field['type'] === 'date')
        <input id="{{ $id }}" name="{{ $name }}" type="date" value="{{ $bound }}" class="field-input">
    @elseif ($field['type'] === 'number')
        <input id="{{ $id }}" name="{{ $name }}" inputmode="decimal" value="{{ $bound }}" class="field-input">
    @else
        <input id="{{ $id }}" name="{{ $name }}" value="{{ $bound }}" class="field-input">
    @endif
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
