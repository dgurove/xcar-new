{{-- Строка уведомления — шторка колокольчика (`mini`) и страница «Уведомления» одним куском (18.11.2026): лицо (аватар, логотип
     вендора или значок вида), машина и время, ниже — что; непрочитанное — полужирной машиной и лаймовой точкой. Что рисовать —
     `NoticeFaces::row` (и для строк до 18.11, без `car`). День — заголовком группы, у строки только время. --}}
@props(['item', 'mini' => false, 'alarm' => false])
@php $r = \App\Notifications\NoticeFaces::row($item->data); @endphp
@if ($r['user'])
    <x-ui.avatar :user="$r['user']" :size="$mini ? 28 : 32" class="notice-face"/>
@elseif ($r['vendor'])
    <x-vendor.logo :vendor="$r['vendor']" class="notice-face notice-logo"/>
@else
    <span class="notice-face notice-icon"><x-ui.icon :name="$alarm ? 'fire' : $r['icon']" class="size-4"/></span>
@endif
<span class="min-w-0 flex-1">
    <span class="flex items-center gap-2">
        <span @class(['min-w-0 flex-1 truncate', 'font-medium' => ! $item->read_at, 'text-danger' => $alarm])>{{ $r['car'] }}</span>
        @unless ($item->read_at)<span class="notice-dot {{ $alarm ? 'bg-danger' : 'bg-accent' }}"></span>@endunless
        <time class="nums shrink-0 text-xs text-ink-dim">{{ $item->created_at->timezone('Europe/Moscow')->format('H:i') }}</time>
    </span>
    @if ($r['what'])
        <span @class(['notice-what', 'notice-what--mini' => $mini])>{{ $r['what'] }}@if ($r['more'] && ! $mini) <span class="text-ink-dim">{{ $r['more'] }}</span>@endif</span>
    @endif
</span>
