{{-- «Куда присылать предложения» (владелец 03.11.2026: «либо в телеграме, либо в вацапе, либо и там и там — понятным
     языком, нарисовать, анимировать»). Сверху сцена: в телефон приходит PDF, переписка по очереди Telegram и WhatsApp. Ниже
     две строки: бот предложений — каждое ТС лично; WhatsApp — открытое всем в группе, только ваше — лично. У каждой —
     ожидание и «Готово» (`offers_feed_controller`). Открывают строка в начале «Предложений» и строки профиля
     (offers-feed:open). WhatsApp привязывается тем, откуда пришло сообщение с кодом (`WhatsApp\LinkCode`). --}}
@php
    $user = auth()->user();
    $feed = new \App\Offers\Feed($user);
    $foreign = ! $user->linksOwnTelegram();
    $bot = app(\App\Telegram\Offers\OffersBot::class);
    $start = $feed->telegram() && ! $foreign ? \App\Telegram\StartLink::link($user) : null;
    $group = $feed->whatsapp() ? app(\App\WhatsApp\Sender::class)->group() : ['name' => null, 'invite' => null];
    $groupName = $group['name'] ?: 'XCar | Предложения';
    // Строки выбора: канал, знак, название, что приходит, подключён ли.
    $rows = array_filter([
        $feed->telegram() ? ['tg', 'telegram', 'Telegram', 'Каждое новое ТС лично вам', $feed->subscribed(), 'подписаны'] : null,
        $feed->whatsapp() ? ['wa', 'whatsapp', 'WhatsApp', 'Все новые — в группе, ваши — лично', $feed->linked(), 'подключён'] : null,
    ]);
@endphp
@if ($feed->any())
<div class="contents" id="offers-feed" data-controller="sheet offers-feed" @if ($feed->telegram() && $feed->whatsapp()) data-offers-feed-both-value="1" @endif
    @if ($foreign)
        data-offers-feed-foreign-value="1"
    @else
        @if ($start) data-offers-feed-tg-app-value="tg://resolve?domain={{ $bot->username() }}&start={{ $start }}" data-offers-feed-tg-web-value="{{ $bot->startUrl($start) }}" @endif
        @if ($feed->whatsapp()) data-offers-feed-wa-app-value="{{ \App\WhatsApp\LinkCode::app($user) }}" data-offers-feed-wa-web-value="{{ \App\WhatsApp\LinkCode::url($user) }}" @endif
    @endif>
    <x-ui.sheet id="offers-feed-sheet" bare>
        <div class="tg-step" data-offers-feed-target="step" data-step="pick">
            <div class="tg-scene feed-scene" data-offers-feed-target="scene" data-app="{{ $feed->telegram() ? 'tg' : 'wa' }}">
                <div class="feed-chat" aria-hidden="true">
                    <span class="feed-tg"><x-telegram.logo class="!size-6"/>XCar Предложения</span>
                    <span class="feed-wa"><x-whatsapp.logo class="!size-6"/>{{ $groupName }}</span>
                </div>
                <img src="/pwa/site/icon-maskable-512.png" alt="" class="tg-avatar">
                <div class="tg-message">
                    <span class="tg-typing" aria-hidden="true"><i></i><i></i><i></i></span>
                    <div class="tg-bubble">
                        <div class="tg-file"><span class="tg-file-icon"><x-ui.icon name="file" class="size-5"/></span><span class="min-w-0"><span class="block truncate">Toyota Camry, 2021.pdf</span><span class="tg-file-meta">1,3 МБ</span></span></div>
                        Toyota Camry, 2021<br>Краснодар, 23<br>2 450 000
                        <span class="feed-link">xcar.ru/offers/2611023476</span>
                    </div>
                    <div class="tg-key">Открыть в XCar</div>
                </div>
            </div>
            <div class="tg-lead">
                <h2 class="text-lg">Новые предложения в телефон</h2>
                <p>Каждое ТС с PDF для клиента сразу после выхода — просто перешлите. Выберите, куда, или оба</p>
            </div>
            <div class="list">
                @foreach ($rows as [$channel, $logo, $name, $sub, $on, $onWord])
                    @if ($on)
                        <div class="row">
                    @else
                        <button type="button" class="row w-full text-left" data-action="offers-feed#go" data-offers-feed-channel-param="{{ $channel }}">
                    @endif
                        <span class="row-icon row-icon-s {{ $channel }}-tile"><x-dynamic-component :component="$logo.'.logo'" plain class="size-[18px]"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block">{{ $name }}</span>
                            <span class="block text-sm text-ink-muted">{{ $sub }}</span>
                        </span>
                        @if ($on)
                            <span class="feed-on"><x-ui.icon name="check" class="size-4"/>{{ $onWord }}</span>
                        @else
                            <x-ui.chevron/>
                        @endif
                    @if ($on)
                        </div>
                    @else
                        </button>
                    @endif
                @endforeach
            </div>
            <div class="flex flex-col gap-1">
                @if ($foreign)<p class="tg-stop" data-offers-feed-target="stop" hidden>Вы вошли как {{ $user->firstName() }}. Подключает менеджер сам, со своего телефона</p>@endif
                <button type="button" class="btn btn-ghost w-full" data-action="offers-feed#dismiss">Не сейчас</button>
            </div>
        </div>

        <div class="tg-step" data-offers-feed-target="step" data-step="wait-tg" hidden>
            <div class="tg-wait"><x-telegram.logo class="size-full"/></div>
            <div class="tg-lead">
                <h2 class="text-lg">Нажмите «Запустить»</h2>
                <p>Вернитесь сюда, всё подключится само</p>
            </div>
            @if ($start)<div class="tg-qr hidden sm:block">{!! \App\Support\Qr::svg($bot->startUrl($start)) !!}</div>@endif
            <div class="flex flex-col gap-1">
                <button type="button" class="btn btn-quiet w-full" data-action="offers-feed#go" data-offers-feed-channel-param="tg">Открыть Telegram</button>
                <button type="button" class="btn btn-ghost w-full" data-action="offers-feed#pick">Назад</button>
            </div>
        </div>

        @if ($feed->whatsapp())
            <div class="tg-step" data-offers-feed-target="step" data-step="wait-wa" hidden>
                <div class="tg-wait is-wa"><x-whatsapp.logo class="size-full"/></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Нажмите «Отправить» в WhatsApp</h2>
                    <p>Текст уже набран. Вернитесь сюда, всё подключится само</p>
                </div>
                @unless ($foreign)<div class="tg-qr hidden sm:block">{!! \App\Support\Qr::svg(\App\WhatsApp\LinkCode::url($user)) !!}</div>@endunless
                <div class="flex flex-col gap-1">
                    <button type="button" class="btn btn-quiet w-full" data-action="offers-feed#go" data-offers-feed-channel-param="wa">Открыть WhatsApp</button>
                    <button type="button" class="btn btn-ghost w-full" data-action="offers-feed#pick">Назад</button>
                </div>
            </div>
        @endif

        <div class="tg-step" data-offers-feed-target="step" data-step="done-tg" hidden>
            <div class="tg-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
            <div class="tg-lead">
                <h2 class="text-lg">Готово, {{ $user->firstName() }}</h2>
                <p>Бот пришлёт новые предложения, как только они выйдут</p>
            </div>
            <div class="flex flex-col gap-1">
                <button type="button" class="btn btn-accent w-full" data-action="offers-feed#finish">Отлично</button>
                @if ($feed->whatsapp() && ! $feed->linked())
                    <button type="button" class="btn btn-ghost w-full" data-action="offers-feed#go" data-offers-feed-channel-param="wa">Добавить и WhatsApp</button>
                @endif
            </div>
        </div>

        @if ($feed->whatsapp())
            <div class="tg-step" data-offers-feed-target="step" data-step="done-wa" hidden>
                <div class="tg-done"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="size-9" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
                <div class="tg-lead">
                    <h2 class="text-lg">Готово, {{ $user->firstName() }}</h2>
                    <p>Лично придут предложения, открытые только вам, и просьбы оценить. Все новые — в группе «{{ $groupName }}»</p>
                </div>
                <div class="flex flex-col gap-1">
                    @if ($group['invite'])
                        <a href="{{ $group['invite'] }}" target="_blank" rel="noopener" class="btn btn-whatsapp w-full" data-turbo="false"><x-whatsapp.logo plain/>Вступить в группу</a>
                    @endif
                    <button type="button" class="btn {{ $group['invite'] ? 'btn-ghost' : 'btn-accent' }} w-full" data-action="offers-feed#finish">{{ $group['invite'] ? 'Готово' : 'Отлично' }}</button>
                    @if ($feed->telegram() && ! $feed->subscribed())
                        <button type="button" class="btn btn-ghost w-full" data-action="offers-feed#go" data-offers-feed-channel-param="tg">Добавить и Telegram</button>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.sheet>
</div>
@endif
