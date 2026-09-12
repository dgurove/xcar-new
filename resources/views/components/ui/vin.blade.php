{{-- Поле VIN с ✨: кнопка загорается на семнадцатом знаке, по клику декодер
     заполняет пустые поля формы. Форма несёт data-controller="vin". --}}
@props(['name' => 'vin', 'label' => 'VIN', 'value' => null, 'afterLabel' => null, 'span' => null])
@php
    $id = 'f-'.$name;
    $error = $errors->first($name);
    $bound = old($name, $value);
@endphp
<div class="field vin {{ $span }} {{ $error ? 'field-invalid' : '' }}">
    @if ($afterLabel)<span class="field-label flex items-center gap-1.5"><label for="{{ $id }}">{{ $label }}</label>{{ $afterLabel }}</span>
    @else<label for="{{ $id }}" class="field-label">{{ $label }}</label>@endif
    <div class="vin-box">
        <input id="{{ $id }}" name="{{ $name }}" type="text" value="{{ $bound }}" maxlength="17" autocapitalize="characters" autocomplete="off" spellcheck="false"
            data-vin-target="input" data-action="input->vin#check keydown.enter->vin#enter"
            {{ $attributes->except('id')->merge(['class' => 'field-input uppercase']) }}>
        <button type="button" class="vin-magic" data-vin-target="button" data-action="vin#fill" aria-label="Заполнить по VIN" title="Заполнить по VIN" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path class="spark-1" d="M10 4l1.6 4.4L16 10l-4.4 1.6L10 16l-1.6-4.4L4 10l4.4-1.6L10 4Z"/>
                <path class="spark-2" d="M18 13l.9 2.1L21 16l-2.1.9L18 19l-.9-2.1L15 16l2.1-.9L18 13Z"/>
                <path class="spark-3" d="M17 3l.6 1.4L19 5l-1.4.6L17 7l-.6-1.4L15 5l1.4-.6L17 3Z"/>
            </svg>
        </button>
    </div>
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
