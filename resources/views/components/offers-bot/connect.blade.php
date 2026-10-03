{{-- Подписка на бот предложений @xcar_offers_bot — та же шторка, что подключение Telegram (telegram_controller, шаги
     «предложение → ожидание → готово»), со своими событиями. В сцене чата — то, что придёт в 16:00: анонс с цифрами
     и клавиатура «👍 1» «💤 2». Открывает строка в начале каталога (offers-bot:open). --}}
@php
    $user = auth()->user();
    $bot = app(\App\Telegram\Offers\OffersBot::class);
    $foreign = ! $user->linksOwnTelegram();
    $start = $foreign ? null : \App\Telegram\StartLink::link($user);
@endphp
<div class="contents" id="offers-bot-connect" data-controller="sheet telegram" data-telegram-mode-value="link"
    data-telegram-live-event-value="live:offers-bot" data-telegram-open-event-value="offers-bot:open" data-telegram-state-url-value="/account/offers-bot/state"
    @if ($foreign)
        data-telegram-foreign-value="1"
    @else
        data-telegram-app-value="tg://resolve?domain={{ $bot->username() }}&start={{ $start }}"
        data-telegram-web-value="{{ $bot->startUrl($start) }}"
    @endif>
    <x-ui.sheet id="offers-bot-sheet" bare>
        <div class="tg-step" data-telegram-target="step" data-step="offer">
            <div class="tg-scene" data-telegram-target="scene">
                <img src="/pwa/site/icon-maskable-512.png" alt="" class="tg-avatar">
                <div class="tg-message">
                    <span class="tg-typing" aria-hidden="true"><i></i><i></i><i></i></span>
                    <div class="tg-bubble">
                        Опубликовано 17 предложений, показать их?<br><br>1. Показать<br>2. Напомнить через 1 ч
                        <span class="tg-bubble-meta nums">16:00</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1"><div class="tg-key">👍 1</div><div class="tg-key">💤 2</div></div>
                </div>
            </div>
            <div class="tg-lead">
                <h2 class="text-lg">Предложения в Telegram</h2>
                <p>Каждый день в 16:00, листайте по одному</p>
            </div>
            <div class="flex flex-col gap-1">
                @if ($foreign)<p class="tg-stop" data-telegram-target="stop" hidden>Вы вошли как {{ $user->firstName() }}. Подписывается менеджер сам, со своего телефона</p>@endif
                <button type="button" class="btn btn-telegram w-full" data-action="telegram#go"><x-telegram.logo plain/>Подписаться</button>
                <button type="button" class="btn btn-ghost w-full" data-action="telegram#dismiss">Не сейчас</button>
            </div>
        </div>

        <div class="tg-step" data-telegram-target="step" data-step="wait" hidden>
            <div class="tg-wait"><x-telegram.logo class="size-full"/></div>
            <div class="tg-lead">
                <h2 class="text-lg">Нажмите «Запустить»</h2>
                <p>Вернитесь сюда, всё подключится само</p>
            </div>
            @if ($start)<div class="tg-qr hidden sm:block">{!! \App\Support\Qr::svg($bot->startUrl($start)) !!}</div>@endif
            <div class="flex flex-col gap-1">
                <button type="button" class="btn btn-quiet w-full" data-action="telegram#go">Открыть Telegram</button>
                <button type="button" class="btn btn-ghost w-full" data-action="telegram#dismiss">Отмена</button>
            </div>
        </div>

        <div class="tg-step" data-telegram-target="step" data-step="done" hidden>
            <div class="tg-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
            <div class="tg-lead">
                <h2 class="text-lg">Готово, {{ $user->firstName() }}</h2>
                <p>В 16:00 бот пришлёт новые предложения</p>
            </div>
            <button type="button" class="btn btn-accent w-full" data-action="telegram#finish">Отлично</button>
        </div>
    </x-ui.sheet>
</div>
