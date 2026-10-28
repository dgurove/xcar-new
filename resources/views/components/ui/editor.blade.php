{{-- Редактор письма на Trix: значение в скрытом поле, форматирование минимальное. focus — курсор сразу в начало (ответ на письмо).
     bare — поле письма в почтовом редакторе (`compose-fields`): без рамки и заливки, панель форматирования снизу и спрятана,
     её показывает «Aa» (`.compose[data-format]`). --}}
@props(['name', 'label' => null, 'value' => null, 'focus' => false, 'bare' => false])
@php $id = 'ed-'.$name; $error = $errors->first($name); @endphp
<div class="{{ $bare ? 'editor-bare' : 'field' }} {{ $error ? 'field-invalid' : '' }}" data-controller="editor" @if ($focus) data-editor-focus-value="true" @endif>
    @if ($label)<label for="{{ $id }}" class="field-label">{{ $label }}</label>@endif
    <input id="{{ $id }}" type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}">
    @if ($bare)
        <trix-editor input="{{ $id }}" toolbar="tb-{{ $id }}" class="trix-content" placeholder="Текст письма" data-editor-target="editor"></trix-editor>
        <trix-toolbar id="tb-{{ $id }}"></trix-toolbar>
    @else
        <trix-editor input="{{ $id }}" class="field-input trix-content min-h-48" data-editor-target="editor"></trix-editor>
    @endif
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
