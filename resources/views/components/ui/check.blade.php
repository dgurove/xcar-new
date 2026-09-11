@props(['name', 'value' => '1', 'checked' => false])
<label class="check">
    <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
