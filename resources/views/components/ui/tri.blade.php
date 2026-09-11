{{-- Да / нет / неизвестно одним рядом кнопок. --}}
@props(['name', 'label', 'value' => null])
@php $v = old($name, $value === null ? '' : ($value ? '1' : '0')); @endphp
<div class="field">
    <span class="field-label">{{ $label }}</span>
    <div class="flex rounded-(--radius-m) bg-surface-3 p-1">
        @foreach (['1' => 'Да', '0' => 'Нет', '' => '—'] as $opt => $text)
            <label class="flex-1">
                <input type="radio" name="{{ $name }}" value="{{ $opt }}" class="peer sr-only" @checked((string) $v === $opt)>
                <span class="block rounded-(--radius-s) py-2 text-center text-sm text-ink-muted peer-checked:bg-surface peer-checked:text-ink">{{ $text }}</span>
            </label>
        @endforeach
    </div>
</div>
