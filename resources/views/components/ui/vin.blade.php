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
                <path class="spark-1" d="M11 5.5 L13.25 10.75 L18.5 13 L13.25 15.25 L11 20.5 L8.75 15.25 L3.5 13 L8.75 10.75Z"/>
                <path class="spark-2" d="M19.5 1.8 L20.46 4.04 L22.7 5 L20.46 5.96 L19.5 8.2 L18.54 5.96 L16.3 5 L18.54 4.04Z"/>
                <path class="spark-3" d="M20 14.9 L20.78 16.72 L22.6 17.5 L20.78 18.28 L20 20.1 L19.22 18.28 L17.4 17.5 L19.22 16.72Z"/>
                <path class="spark-4" d="M4.5 3.3 L5.16 4.84 L6.7 5.5 L5.16 6.16 L4.5 7.7 L3.84 6.16 L2.3 5.5 L3.84 4.84Z"/>
            </svg>
        </button>
    </div>
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
