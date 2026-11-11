{{-- Строка `x-ui.info`: значок слева, значение, `sub` — тусклым под ним (адрес под местом, подпись поля). `href` — вся строка
     ссылкой (карта); телефон и прочее нажимаемое — в слоте `extra` под значением. Пустое значение не рисуется. --}}
@props(['icon', 'sub' => null, 'href' => null])
@if (trim($slot) !== '')
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" target="_blank" rel="noopener" @endif {{ $attributes->merge(['class' => 'info-row']) }}>
    <x-ui.icon :name="$icon" class="info-icon size-5"/>
    <span class="min-w-0 flex-1">
        <span class="block break-words">{{ $slot }}</span>
        @if (filled($sub))
            <span class="info-sub">{{ $sub }}</span>
        @endif
        @isset($extra)
            {{ $extra }}
        @endisset
    </span>
</{{ $tag }}>
@endif
