{{-- Строка дела в текущем шаге пути (10.11.2026, владелец про шаг «Забрать автомобиль»: «лютое месиво» — письмо, ДКП, счёт были
     каждый своим видом, оранжевым и зелёными ссылками): значок (у письма — логотип поставщика), фраза, «›» справа, весь ряд — кнопка. Срочность — точкой у
     значка, не цветом текста. `href` — ссылка, `emit` — адрес окна писем (кнопка `letters:open`), `static` — просто строка без «›»,
     иначе кнопка шторки вмещающего `data-controller="sheet"`. `avatar` — человек вместо значка (менеджер, водитель на странице). `danger` — точка красная (просрочено), `urgent` — рыжая. --}}
@props(['icon' => null, 'vendor' => null, 'avatar' => null, 'href' => null, 'emit' => null, 'static' => false, 'urgent' => false, 'danger' => false])
@php $tag = $static ? 'div' : ($href ? 'a' : 'button'); @endphp
<{{ $tag }} @if ($static) @elseif ($href) href="{{ $href }}" @elseif ($emit) type="button" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $emit }}" @else type="button" data-action="sheet#open" @endif {{ $attributes->class('step-task') }}>
    <span class="step-task-icon">
        @if ($avatar)
            <x-ui.avatar :user="$avatar" :size="32"/>
        @elseif ($vendor)
            <x-vendor.logo :vendor="$vendor" class="!size-6"/>
        @else
            <x-ui.icon :name="$icon" class="size-4"/>
        @endif
        @if ($urgent || $danger)
            <span @class(['step-task-dot', 'step-task-dot--danger' => $danger])></span>
        @endif
    </span>
    {{-- sub — вторая строка приглушённо («Показать менеджеру?» под «Письмо от 16.11 10:26», 16.11.2026). --}}
    <span class="min-w-0 flex-1"><span class="block">{{ $slot }}</span>@isset($sub)<span class="step-task-sub">{{ $sub }}</span>@endisset</span>
    @unless ($static)<x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>@endunless
</{{ $tag }}>
