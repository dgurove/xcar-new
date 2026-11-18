@php
    $href = \App\Notifications\NoticeLink::for($item->data); $foreign = str_starts_with($href, 'http') || str_starts_with($href, '/admin');
    // Тревога «нет покупателя» — красной, пока причина не устранена (покупатель, новый срок — строка снова обычная).
    $alarm = ! empty($item->data['alarm']) && ! empty($item->data['offer']) && ($o = \App\Offers\OfferNumber::find((string) ($item->data['offer'] ?? ''))) && \App\Offers\Binding::panics($o);
    // Как капсула (18.11.2026): лицо, машина, ниже коротко что и текст. Строки до капсул (без `car`) — заголовок и текст.
    $car = $item->data['car'] ?? null;
    $head = $car ?: \App\Notifications\Notice::glue($item->data['title']);
    $what = $car ? ($item->data['brief'] ?? $item->data['title']) : ($item->data['text'] ?? null);
    $more = $car && ! empty($item->data['text']) && ! str_contains((string) $what, mb_substr($item->data['text'], 0, 40)) ? $item->data['text'] : null;
    $user = \App\Notifications\NoticeFaces::user($item->data);
    $vendor = $user ? null : \App\Notifications\NoticeFaces::vendor($item->data);
@endphp
<x-ui.swipe id="notice-{{ $item->id }}">
    {{-- Ссылка ведёт сразу на объект; чужой хост или старый /admin — полной загрузкой. Непрочитанное — лаймовой точкой слева. --}}
    <a href="{{ $href }}" @class(['row notice-row', 'alarm-row' => $alarm]) data-notice="{{ $item->id }}" @if ($foreign) data-turbo="false" @endif>
        <span class="notice-dot {{ $alarm ? 'bg-danger' : ($item->read_at ? 'bg-transparent' : 'bg-accent') }}"></span>
        @if ($user)
            <x-ui.avatar :user="$user" :size="32" class="notice-face"/>
        @elseif ($vendor)
            <x-vendor.logo :vendor="$vendor" class="notice-face notice-logo"/>
        @else
            <span class="notice-face notice-icon"><x-ui.icon :name="$alarm ? 'fire' : 'bell'" class="size-4"/></span>
        @endif
        <span class="min-w-0 flex-1">
            <span class="flex items-baseline gap-3">
                <span class="min-w-0 flex-1 truncate {{ $alarm ? 'font-medium text-danger' : ($item->read_at ? '' : 'font-medium') }}">{{ $head }}</span>
                <span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($item->created_at, time: true, short: true) }}</span>
            </span>
            @if ($what)<span class="notice-what">{{ $what }}@if ($more) <span class="text-ink-dim">— {{ $more }}</span>@endif</span>@endif
        </span>
    </a>
    <x-slot:actions>
        <form method="post" action="/account/notifications/{{ $item->id }}/read" data-queue>@csrf<button type="submit" class="swipe-btn swipe-btn-accent" aria-label="{{ $item->read_at ? 'Не прочитано' : 'Прочитано' }}"><x-ui.icon :name="$item->read_at ? 'eye-off' : 'eye'" class="size-5"/></button></form>
    </x-slot:actions>
</x-ui.swipe>
