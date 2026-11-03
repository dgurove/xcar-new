{{-- Строка в начале «Предложений» (владелец 03.10.2026: «строка в начале списка»; 03.11.2026 — Telegram или WhatsApp):
     пока ничего не подключено и не скрыта (× — на 30 дней). Вся строка открывает шторку «Куда присылать» (x-offers-feed.sheet).
     Подключить можно только Telegram (WhatsApp ещё не открыт) — строка про Telegram, как раньше. --}}
@php
    $user = auth()->user();
    $feed = $user ? new \App\Offers\Feed($user) : null;
    $both = $feed?->telegram() && $feed->whatsapp();
@endphp
@if ($feed?->banner())
    <div class="list mb-4">
        <div class="row gap-3">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="offers-feed:open">
                @if ($both)
                    <span class="feed-logos" aria-hidden="true"><x-telegram.logo class="!size-7"/><x-whatsapp.logo class="!size-7"/></span>
                @elseif ($feed->telegram())
                    <x-telegram.logo class="size-10"/>
                @else
                    <x-whatsapp.logo class="size-10"/>
                @endif
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $both ? 'Предложения в телефон' : ($feed->telegram() ? 'Предложения в Telegram' : 'Предложения в WhatsApp') }}</span>
                    <span class="block text-sm text-ink-muted">{{ $both ? 'Telegram или WhatsApp, PDF для клиента' : 'Новые ТС сразу, с PDF для клиента' }}</span>
                </span>
                <span class="btn btn-s {{ $both ? 'btn-accent' : ($feed->telegram() ? 'btn-telegram' : 'btn-whatsapp') }} shrink-0 !h-8 rounded-full !px-3.5 max-sm:hidden">{{ $feed->telegram() && ! $both ? 'Подписаться' : 'Подключить' }}</span>
            </button>
            <form method="post" action="/account/offers-bot/hide" class="contents">
                @csrf<button class="sheet-close -mr-1" aria-label="Скрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
            </form>
        </div>
    </div>
    <x-offers-feed.sheet/>
@endif
