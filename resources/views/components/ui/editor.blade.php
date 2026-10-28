{{-- Редактор письма на Trix: значение в скрытом поле, форматирование минимальное. focus — курсор сразу в начало (ответ на письмо). --}}
@props(['name', 'label' => null, 'value' => null, 'focus' => false])
@php $id = 'ed-'.$name; $error = $errors->first($name); @endphp
<div class="field {{ $error ? 'field-invalid' : '' }}" data-controller="editor" @if ($focus) data-editor-focus-value="true" @endif>
    @if ($label)<label for="{{ $id }}" class="field-label">{{ $label }}</label>@endif
    <input id="{{ $id }}" type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}">
    <trix-editor input="{{ $id }}" class="field-input trix-content min-h-48" data-editor-target="editor"></trix-editor>
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
