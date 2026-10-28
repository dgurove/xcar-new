{{-- Адресаты письма тегами, как в Gmail (`recipients_controller`): строка шапки редактора «Кому» / «Копия». Поле формы —
     скрытое `name` через запятую, его читают сервер и черновик; names — имена известных адресов для тегов. --}}
@props(['name', 'label', 'value' => '', 'url', 'names' => [], 'required' => false, 'hidden' => false])
@php $id = 'rc-'.$name; $error = $errors->first($name); @endphp
<div {{ $attributes->merge(['class' => 'compose-row compose-recipients'.($error ? ' is-invalid' : '')]) }} @if ($hidden) hidden @endif
     data-controller="recipients" data-recipients-url-value="{{ $url }}" data-recipients-names-value="{{ json_encode((object) $names, JSON_UNESCAPED_UNICODE) }}"
     data-action="click->recipients#focus">
    <label for="{{ $id }}" class="compose-label">{{ $label }}</label>
    <div class="recipients" data-action="click->recipients#focus">
        <input type="text" name="{{ $name }}" hidden value="{{ old($name, $value) }}" data-recipients-target="value" data-action="change->recipients#load" @if ($required) data-required @endif>
        <input id="{{ $id }}" type="text" inputmode="email" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="next"
               class="recipients-input" data-recipients-target="input" data-action="keydown->recipients#key input->recipients#typed paste->recipients#paste blur->recipients#blur">
    </div>
    {{ $slot }}
    <div class="recipients-pop" hidden data-recipients-target="pop"></div>
    <p class="field-error compose-error" data-recipients-error @if (! $error) hidden @endif>{{ $error }}</p>
</div>
