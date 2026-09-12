@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'options' => null, 'placeholder' => null, 'afterLabel' => null, 'span' => null])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['[', ']'], ['-', ''], $name));
    $error = $errors->first($name);
    $bound = old($name, $value);
@endphp
<div class="field {{ $span }} {{ $error ? 'field-invalid' : '' }}">
    @if ($label)<label for="{{ $id }}" class="field-label {{ $afterLabel ? 'flex items-center gap-1.5' : '' }}">{{ $label }}@if ($afterLabel) {{ $afterLabel }}@endif</label>@endif
    @if ($options !== null)
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id')->merge(['class' => 'field-input']) }}>
            @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected((string) $bound === (string) $optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" placeholder="{{ $placeholder }}" {{ $attributes->except('id')->merge(['class' => 'field-input']) }}>{{ $bound }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" placeholder="{{ $placeholder }}"
            @if ($type !== 'password' && $type !== 'file') value="{{ $bound }}" @endif
            {{ $attributes->except('id')->merge(['class' => 'field-input']) }}>
    @endif
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
