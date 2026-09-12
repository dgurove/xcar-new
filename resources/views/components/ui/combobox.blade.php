{{-- Поле-справочник: видимый текст + скрытый id. --}}
@props(['name', 'label', 'url', 'create' => null, 'value' => null, 'text' => null, 'depends' => null, 'param' => 'brand', 'resets' => null])
@php $id = 'f-'.$name; $error = $errors->first($name); @endphp
<div id="cb-{{ $name }}" class="field combobox {{ $error ? 'field-invalid' : '' }}" data-controller="combobox" data-combobox-url-value="{{ $url }}" @if ($resets) data-combobox-resets-value="{{ $resets }}" @endif
     @if ($create) data-combobox-create-value="{{ $create }}" @endif @if ($depends) data-combobox-depends-value="{{ $depends }}" data-combobox-param-value="{{ $param }}" @endif
     data-action="combobox:reset->combobox#reset">
    <label for="{{ $id }}" class="field-label">{{ $label }}</label>
    <input id="{{ $id }}" type="text" class="field-input" value="{{ $text }}" autocomplete="off" autocorrect="off" autocapitalize="words" data-combobox-target="input"
           data-action="input->combobox#search focus->combobox#open" {{ $attributes }}>
    <input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}" data-combobox-target="hidden">
    <div class="combobox-list" data-combobox-target="list" hidden></div>
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
