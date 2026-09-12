{{-- Глазик у подписи поля: показывать на сайте или нет. Скрытый чекбокс, две иконки. --}}
@props(['name', 'checked' => false, 'value' => '1'])
<label {{ $attributes->merge(['class' => 'eye', 'title' => 'Показывать на сайте']) }}>
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked))>
    <x-ui.icon name="eye" class="eye-on"/>
    <x-ui.icon name="eye-off" class="eye-off"/>
</label>
