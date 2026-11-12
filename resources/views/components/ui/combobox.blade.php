{{-- Поле-справочник: видимый текст + скрытый id. textName — имя видимого поля: вписанное, не выбранное из списка, тоже
     уходит формой (ТС не из системы у ручной ссылки на оплату). --}}
@props(['name', 'label', 'url', 'create' => null, 'value' => null, 'text' => null, 'depends' => null, 'param' => 'brand', 'resets' => null, 'required' => false, 'textName' => null, 'placeholder' => null, 'id' => null, 'pinned' => null, 'form' => null])
{{-- form — поле стоит вне своей формы (марка в «Ценах» окна, 12.11.2026): скрытое поле и вписанный текст идут в неё атрибутом. --}}
{{-- pinned — строки первыми в списке (значения источников машины, `x-car.block`): [id, label, hint, brand?]. id — когда то же поле стоит на странице дважды (две шторки «За менеджера» и «Интерес»): иначе подпись вела в чужое. --}}
@php $box = $id ? 'cb-'.$id : 'cb-'.$name; $id ??= 'f-'.$name; $error = $errors->first($name); @endphp
<div id="{{ $box }}" class="field combobox {{ $error ? 'field-invalid' : '' }}" data-controller="combobox" data-combobox-url-value="{{ $url }}" @if ($resets) data-combobox-resets-value="{{ $resets }}" @endif
     @if ($create) data-combobox-create-value="{{ $create }}" @endif @if ($depends) data-combobox-depends-value="{{ $depends }}" data-combobox-param-value="{{ $param }}" @endif
     @if ($pinned) data-combobox-pinned-value="{{ json_encode(array_values($pinned), JSON_UNESCAPED_UNICODE) }}" @endif
     data-action="combobox:reset->combobox#reset">
    @if ($label !== '')<label for="{{ $id }}" class="field-label">{{ $label }}@if ($required)<x-ui.req/>@endif</label>@endif
    <input id="{{ $id }}" type="text" class="field-input" @if ($textName) name="{{ $textName }}" @if ($form) form="{{ $form }}" @endif @endif @if ($placeholder) placeholder="{{ $placeholder }}" @endif value="{{ $textName ? old($textName, $text) : $text }}" autocomplete="off" autocorrect="off" autocapitalize="words" data-combobox-target="input"
           data-action="input->combobox#search focus->combobox#open" {{ $attributes }}>
    <input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}" data-combobox-target="hidden" @if ($form) form="{{ $form }}" @endif>
    <div class="combobox-list" data-combobox-target="list" popover="manual" hidden></div>
    {{ $hint ?? '' }}
    @if ($error)<p class="field-error">{{ $error }}</p>@endif
</div>
