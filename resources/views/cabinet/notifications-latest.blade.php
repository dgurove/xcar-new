{{-- Последние уведомления в шторке колокольчика — строками-капсулами (18.11.2026): лицо, машина, коротко что, время справа;
     непрочитанное — лаймовой точкой. Строки до капсул (без `car`) — заголовок и текст. --}}
<turbo-frame id="{{ $frame }}">
    @if ($items->isEmpty())
        <p class="rounded-(--radius-l) bg-surface-2 px-4 py-8 text-center text-sm text-ink-muted">Пока тихо</p>
    @else
        <ul class="divide-y divide-line">
            @foreach ($items as $item)
                @php
                    $href = \App\Notifications\NoticeLink::for($item->data); $foreign = str_starts_with($href, 'http') || str_starts_with($href, '/admin');
                    $car = $item->data['car'] ?? null;
                    $head = $car ?: \App\Notifications\Notice::glue($item->data['title']);
                    $what = $car ? ($item->data['brief'] ?? $item->data['title']) : ($item->data['text'] ?? null);
                    $user = \App\Notifications\NoticeFaces::user($item->data);
                    $vendor = $user ? null : \App\Notifications\NoticeFaces::vendor($item->data);
                @endphp
                <li>
                    <a href="{{ $href }}" class="notice-mini" data-turbo-frame="_top" data-notice="{{ $item->id }}" @if ($foreign) data-turbo="false" @endif>
                        @if ($user)
                            <x-ui.avatar :user="$user" :size="28" class="notice-face"/>
                        @elseif ($vendor)
                            <x-vendor.logo :vendor="$vendor" class="notice-face notice-logo"/>
                        @else
                            <span class="notice-face notice-icon"><x-ui.icon name="bell" class="size-3.5"/></span>
                        @endif
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $head }}</span>
                                @unless ($item->read_at)<span class="notice-dot bg-accent"></span>@endunless
                                <span class="shrink-0 text-xs text-ink-dim">{{ \App\Support\Ago::html($item->created_at, time: true, short: true) }}</span>
                            </span>
                            @if ($what)<span class="block truncate text-sm text-ink-muted">{{ $what }}</span>@endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</turbo-frame>
