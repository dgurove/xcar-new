{{-- Пресеты списка: чипы с прокруткой, состояние в URL. --}}
@props(['items', 'current', 'param' => 'preset', 'counts' => []])
<div class="presets">
    @foreach ($items as $key => $label)
        <a href="{{ request()->fullUrlWithQuery([$param => $key === array_key_first($items) ? null : $key, 'page' => null]) }}" class="preset" @if ($current === $key) aria-current="true" @endif>
            {{ $label }}@if (!empty($counts[$key])) <span class="ml-1 opacity-70">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</div>
