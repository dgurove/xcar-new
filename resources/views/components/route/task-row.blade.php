{{-- Строка дела в текущем шаге пути (10.11.2026, владелец про шаг «Забрать автомобиль»: «лютое месиво» — письмо, ДКП, счёт были
     каждый своим видом, оранжевым и зелёными ссылками): значок (у письма — логотип поставщика), фраза, «›» справа, весь ряд — кнопка. Срочность — точкой у
     значка, не цветом текста. `href` — ссылка, иначе кнопка шторки вмещающего `data-controller="sheet"`. --}}
@props(['icon' => null, 'vendor' => null, 'href' => null, 'urgent' => false])
@php $tag = $href ? 'a' : 'button'; @endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @else type="button" data-action="sheet#open" @endif {{ $attributes->class('step-task') }}>
    <span class="step-task-icon">
        @if ($vendor)
            <x-vendor.logo :vendor="$vendor" class="!size-6"/>
        @else
            <x-ui.icon :name="$icon" class="size-4"/>
        @endif
        @if ($urgent)
            <span class="step-task-dot"></span>
        @endif
    </span>
    <span class="min-w-0 flex-1">{{ $slot }}</span>
    <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
</{{ $tag }}>
