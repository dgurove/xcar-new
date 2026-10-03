{{-- Строка «Предложения в Telegram» в начале каталога (владелец 03.10.2026: «строка в начале списка») — менеджеру, пока
     он не подписан на бот предложений и не скрыл её (× — на 30 дней). Вся строка открывает шторку подписки. --}}
@php
    $user = auth()->user();
    $hidden = $user?->notification_settings['offers_bot_hidden'] ?? null;
    $show = $user && $user->isManager() && ! $user->is_demo && \App\Telegram\Offers\Handler::eligible($user)
        && app(\App\Telegram\Offers\OffersBot::class)->configured() && ! \App\Telegram\Offers\Handler::subscribed($user)
        && (! $hidden || $hidden < now()->toDateString());
@endphp
@if ($show)
    <div class="list mb-4">
        <div class="row gap-3">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="offers-bot:open">
                <x-telegram.logo class="size-10"/>
                <span class="min-w-0 flex-1">
                    <span class="block">Предложения в Telegram</span>
                    <span class="block text-sm text-ink-muted">Каждый день в 16:00</span>
                </span>
                <span class="btn btn-s btn-telegram shrink-0 !h-8 rounded-full !px-3.5 max-sm:hidden">Подписаться</span>
            </button>
            <form method="post" action="/account/offers-bot/hide" class="contents">
                @csrf<button class="sheet-close -mr-1" aria-label="Скрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
            </form>
        </div>
    </div>
    <x-offers-bot.connect/>
@endif
