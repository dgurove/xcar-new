@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'options' => null, 'placeholder' => null, 'afterLabel' => null, 'span' => null])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['[', ']'], ['-', ''], $name));
    $error = $errors->first($name);
    $bound = old($name, $value);
    // Клавиатура по смыслу поля: цифры для цен и пробега, заглавные без автозамены
    // для VIN и госномера, свой тип для телефона и почты. Явные атрибуты важнее.
    $base = strtolower(preg_replace('/\[.*$/', '', $name));
    $keys = match (true) {
        in_array($base, ['price', 'amount', 'mileage', 'year', 'power', 'volume', 'sum', 'cost'], true) || str_ends_with($base, '_price') || str_ends_with($base, '_km') => ['inputmode' => 'numeric', 'autocomplete' => 'off'],
        in_array($base, ['vin', 'plate'], true) => ['autocapitalize' => 'characters', 'autocorrect' => 'off', 'spellcheck' => 'false', 'autocomplete' => 'off'],
        in_array($base, ['phone', 'tel'], true) => ['type' => 'tel', 'inputmode' => 'tel', 'autocomplete' => 'tel'],
        $base === 'email' => ['type' => 'email', 'inputmode' => 'email', 'autocomplete' => 'email', 'autocapitalize' => 'none', 'autocorrect' => 'off'],
        in_array($base, ['q', 'search'], true) => ['type' => 'search', 'enterkeyhint' => 'search', 'autocomplete' => 'off'],
        default => [],
    };
    if ($type !== 'text') unset($keys['type']);
    $type = $keys['type'] ?? $type;
    unset($keys['type']);
@endphp
<div class="field {{ $span }} {{ $error ? 'field-invalid' : '' }}">
    @if ($label && $afterLabel)<span class="field-label flex items-center gap-1.5"><label for="{{ $id }}">{{ $label }}</label>{{ $afterLabel }}</span>
    @elseif ($label)<label for="{{ $id }}" class="field-label">{{ $label }}</label>@endif
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
            {{ $attributes->except('id')->merge(['class' => 'field-input'] + $keys) }}>
    @endif
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
