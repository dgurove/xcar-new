@props(['name', 'value' => '1', 'checked' => false, 'old' => true])
<label class="check">
    <input type="checkbox" switch name="{{ $name }}" value="{{ $value }}" @checked($old ? old($name, $checked) : $checked) {{ $attributes }}>
    <span>{{ $slot }}</span>
</label>
