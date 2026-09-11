{{-- Переключатель разделов одного уровня: две-три ссылки одной полосой. --}}
@props(['items', 'current'])
<div class="flex rounded-(--radius-m) bg-surface p-1">
    @foreach ($items as $href => $label)
        <a href="{{ $href }}" class="flex-1 rounded-(--radius-s) py-2 text-center text-sm {{ $current === $href ? 'bg-chrome text-white dark:bg-white dark:text-chrome' : 'text-ink-muted' }}">{{ $label }}</a>
    @endforeach
</div>
