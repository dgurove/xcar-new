{{-- Профиль: «Новые предложения» — куда они приходят (`Offers\Feed`), группой строк как в «Настройках» iPhone. Подключено —
     шторка с «Отключить» (у WhatsApp — его номер и группа), нет — «Подписаться» / «Подключить» открывают шторку «Куда
     присылать» (x-offers-feed.sheet). Так в бот можно подписаться и после × у строки в «Предложениях». --}}
@php
    $user = auth()->user();
    $feed = new \App\Offers\Feed($user);
    $group = $feed->whatsapp() ? app(\App\WhatsApp\Sender::class)->group() : ['name' => null, 'invite' => null];
@endphp
@if ($feed->any())
    <div class="[&>.list-head]:pt-0">
        <div class="list-head">Новые предложения</div>
        <div class="list">
            @if ($feed->telegram())
                @if ($feed->subscribed())
                    <div data-controller="sheet" class="contents">
                        <x-ui.sheet-row>
                            <span class="row-icon row-icon-s tg-tile"><x-telegram.logo plain class="size-[18px]"/></span>
                            <span class="min-w-0 flex-1">Бот предложений</span>
                            <span class="text-ink-muted">подписаны</span>
                            <x-ui.chevron/>
                        </x-ui.sheet-row>
                        <x-ui.sheet id="offers-bot-account" title="Бот предложений">
                            <div class="flex flex-col gap-5">
                                <div class="flex flex-col items-center gap-1 text-center">
                                    <x-telegram.logo class="mb-2 size-16"/>
                                    <span class="text-lg">Каждое новое ТС лично вам</span>
                                    <span class="text-sm text-ink-muted">PDF с текстом для клиента — в Telegram</span>
                                </div>
                                <form method="post" action="/account/offers-bot" class="list" data-turbo-confirm="Отписаться от бота предложений?" data-turbo-confirm-label="Отписаться" data-turbo-confirm-text="Новые предложения перестанут приходить в Telegram">
                                    @csrf @method('delete')
                                    <button class="row w-full justify-center text-danger">Отписаться</button>
                                </form>
                            </div>
                        </x-ui.sheet>
                    </div>
                @else
                    <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="offers-feed:open">
                        <span class="row-icon row-icon-s tg-tile"><x-telegram.logo plain class="size-[18px]"/></span>
                        <span class="min-w-0 flex-1">Бот предложений</span>
                        <span class="font-medium feed-verb-tg">Подписаться</span>
                        <x-ui.chevron/>
                    </button>
                @endif
            @endif
            @if ($feed->whatsapp())
                @if ($feed->linked())
                    <div data-controller="sheet" class="contents">
                        <x-ui.sheet-row>
                            <span class="row-icon row-icon-s wa-tile"><x-whatsapp.logo plain class="size-[18px]"/></span>
                            <span class="min-w-0 flex-1">WhatsApp</span>
                            <span class="truncate text-ink-muted">{{ $user->whatsapp_phone ? \App\Support\Phone::format($user->whatsapp_phone) : 'подключён' }}</span>
                            <x-ui.chevron/>
                        </x-ui.sheet-row>
                        <x-ui.sheet id="whatsapp-account" title="WhatsApp">
                            <div class="flex flex-col gap-5">
                                <div class="flex flex-col items-center gap-1 text-center">
                                    <x-whatsapp.logo class="mb-2 size-16"/>
                                    <span class="text-lg">{{ $user->whatsapp_phone ? \App\Support\Phone::format($user->whatsapp_phone) : $user->name }}</span>
                                    @if ($user->whatsapp_linked_at)<span class="text-sm text-ink-muted">подключён {{ \App\Support\Ago::html($user->whatsapp_linked_at, words: false) }}</span>@endif
                                </div>
                                <div class="list">
                                    <div class="row">
                                        <span class="min-w-0 flex-1">Лично</span>
                                        <span class="text-ink-muted">только ваши и оценка</span>
                                    </div>
                                    @if ($group['invite'])
                                        <a href="{{ $group['invite'] }}" target="_blank" rel="noopener" class="row" data-turbo="false">
                                            <span class="min-w-0 flex-1">Все новые</span>
                                            <span class="truncate feed-verb-wa">{{ $group['name'] ?: 'XCar | Предложения' }}</span>
                                            <x-ui.chevron/>
                                        </a>
                                    @endif
                                </div>
                                <form method="post" action="/account/whatsapp" class="list" data-turbo-confirm="Отключить WhatsApp?" data-turbo-confirm-label="Отключить" data-turbo-confirm-text="Предложения только вам и просьбы оценить перестанут приходить в WhatsApp. Из группы выйдите в самом WhatsApp">
                                    @csrf @method('delete')
                                    <button class="row w-full justify-center text-danger">Отключить WhatsApp</button>
                                </form>
                            </div>
                        </x-ui.sheet>
                    </div>
                @else
                    <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="offers-feed:open">
                        <span class="row-icon row-icon-s wa-tile"><x-whatsapp.logo plain class="size-[18px]"/></span>
                        <span class="min-w-0 flex-1">WhatsApp</span>
                        <span class="font-medium feed-verb-wa">Подключить</span>
                        <x-ui.chevron/>
                    </button>
                @endif
            @endif
        </div>
    </div>
    <x-offers-feed.sheet/>
@endif
