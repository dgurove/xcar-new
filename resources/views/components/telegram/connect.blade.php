{{-- Подключение Telegram — одна шторка на страницу, в оболочке у того, кому можно подключить (User::canLinkTelegram).
     Три шага в одной шторке: предложение (пример сообщения бота, синяя кнопка, «Не сейчас») → ожидание
     («Нажмите «Запустить»», на ПК QR) → готово. Открывают её события telegram:open (карточка «Сделок», строка
     профиля, настройки), сама — один раз при входе на /offers и /deals и после подтверждения ценой
     (User::telegramMoments, telegram_controller). --}}
@php
    $user = auth()->user();
    $bot = app(\App\Telegram\Bot::class);
    $can = $user?->canLinkTelegram() ?? false;
    $start = $can ? \App\Telegram\StartLink::link($user) : null;
    $moments = $can ? $user->telegramMoments() : [];
@endphp
@if ($can)
    {{-- Постоянная между визитами: морф после подтверждения ценой не закрывает только что открытую шторку. --}}
    <div class="contents" id="telegram-connect" data-turbo-permanent data-controller="sheet telegram" data-telegram-mode-value="link"
        data-telegram-moments-value='@json($moments)'
        data-telegram-app-value="tg://resolve?domain={{ $bot->username() }}&start={{ $start }}"
        data-telegram-web-value="{{ $bot->startUrl($start) }}">
        <x-ui.sheet id="telegram-connect-sheet" bare>
            <div class="tg-step" data-telegram-target="step" data-step="offer">
                <x-telegram.bubble :message="\App\Telegram\Preview::for($user)"/>
                <div class="tg-lead">
                    <h2 class="text-lg" data-telegram-target="title" @if ($user->isManager()) data-bid="Узнайте первым, если выберут вас" @endif>{{ $user->isManager() ? 'Узнавайте о сделках первым' : 'Счета и оплаты в Telegram' }}</h2>
                    <p>{{ $user->isManager() ? 'Когда выбрали вас, нужен ваш ответ или пришли деньги. Без рассылок' : 'Оплаты, выплаты и просроченные счета с кнопкой решения' }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-telegram w-full" data-action="telegram#go"><x-telegram.logo plain/>Подключить Telegram</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="telegram#dismiss">Не сейчас</button>
                </div>
            </div>

            <div class="tg-step" data-telegram-target="step" data-step="wait" hidden>
                <div class="tg-wait"><x-telegram.logo class="size-full"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Нажмите «Запустить»</h2>
                    <p>Вернитесь сюда, всё подключится само</p>
                </div>
                {{-- С компьютера Telegram обычно в телефоне: камерой по коду. --}}
                <div class="tg-qr hidden sm:block">{!! \App\Support\Qr::svg($bot->startUrl($start)) !!}</div>
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-quiet w-full" data-action="telegram#go">Открыть Telegram</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="telegram#dismiss">Отмена</button>
                </div>
            </div>

            <div class="tg-step" data-telegram-target="step" data-step="done" hidden>
                <div class="tg-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Готово, {{ $user->firstName() }}</h2>
                    <p>Первое сообщение уже в Telegram</p>
                </div>
                <button type="button" class="btn btn-accent w-full" data-action="telegram#finish">Отлично</button>
            </div>
        </x-ui.sheet>
    </div>
@endif
