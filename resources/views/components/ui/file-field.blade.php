{{-- Поле файла видом поля ввода: системный «Choose File… No file chosen» спрятан, вместо него скрепка и «Приложить»,
     после выбора — имя файла (file_name_controller). --}}
@props(['name', 'label' => null, 'accept' => null, 'span' => null])
@php $id = 'f-'.$name; $error = $errors->first($name); @endphp
<div class="field {{ $span }} {{ $error ? 'field-invalid' : '' }}" data-controller="file-name">
    @if ($label)<span class="field-label">{{ $label }}</span>@endif
    <label for="{{ $id }}" class="field-input flex cursor-pointer items-center gap-2">
        <x-ui.icon name="clip" class="size-4 shrink-0 text-ink-dim"/>
        <span class="min-w-0 truncate text-ink-muted" data-file-name-target="label">Приложить</span>
    </label>
    <input id="{{ $id }}" name="{{ $name }}" type="file" @if ($accept) accept="{{ $accept }}" @endif class="sr-only" data-action="change->file-name#show" {{ $attributes }}>
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
