{{-- Настройки уведомлений: каналы, о чём, тихие часы — строки с тумблерами, сохраняются сами;
     критичное без тумблера с пометкой «всегда»; внизу «Проверить». --}}
@php
    $push = (bool) config('xcar.vapid.public');
    $off = $settings['off'] ?? [];
@endphp
<x-ui.cabinet title="Настройки уведомлений" :back="['Уведомления', '/account/notifications']">
    <form method="post" action="/account/notifications/settings" class="flex flex-col gap-3" data-controller="autosubmit">
        @csrf @method('put')

        <section>
            <h2 class="list-head">Каналы</h2>
            <div class="list">
                @if ($push)
                    {{-- Пуш — про это устройство, не про аккаунт: тумблером управляет push_controller, не форма. --}}
                    <label class="row row-switch" data-controller="push">
                        <x-ui.row-icon name="bell"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">На этот телефон</span>
                            <span class="row-sub text-danger" data-push-target="state"></span>
                        </span>
                        <input type="checkbox" class="switch" data-push-target="toggle" data-action="change->push#toggle">
                    </label>
                @endif
                @if ($user->email)
                    <label class="row row-switch">
                        <x-ui.row-icon name="mail"/>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">На почту</span>
                            <span class="row-sub"><span class="tag">{{ $user->email }}</span></span>
                        </span>
                        <input type="checkbox" name="mail" value="1" class="switch" @checked($user->wantsMail()) data-action="change->autosubmit#submit">
                    </label>
                @endif
                @if ($user->canLinkTelegram())
                    <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="telegram:open">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full tg-tile"><x-telegram.logo plain/></span>
                        <span class="min-w-0 flex-1 font-medium">В Telegram</span>
                        <span class="font-medium" style="color: var(--color-telegram)">Подключить</span>
                    </button>
                @elseif ($user->telegram_chat_id)
                    <label class="row row-switch">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full tg-tile"><x-telegram.logo plain/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">В Telegram</span>
                            @if ($user->telegram_username)<span class="row-sub"><span class="tag">{{ '@'.$user->telegram_username }}</span></span>@endif
                        </span>
                        <input type="checkbox" name="telegram" value="1" class="switch" @checked($user->wantsTelegram()) data-action="change->autosubmit#submit">
                    </label>
                @endif
            </div>
        </section>

        {{-- В Telegram — только то, что туда вообще идёт (Categories::telegram); критичное приходит всегда. --}}
        @if ($user->telegram_chat_id && $user->wantsTelegram() && $telegram)
            <section>
                <h2 class="list-head">В Telegram</h2>
                <input type="hidden" name="tg_shown" value="1">
                <div class="list">
                    @foreach ($telegram as $key => $label)
                        <label class="row row-switch">
                            <span class="min-w-0 flex-1 font-medium">{{ $label }}</span>
                            <input type="checkbox" name="tg[]" value="{{ $key }}" class="switch" @checked(! in_array($key, $settings['telegram_off'] ?? [], true)) data-action="change->autosubmit#submit">
                        </label>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($categories['on'] || $categories['always'])
        <section>
            <h2 class="list-head">О чём</h2>
            <div class="list">
                @foreach ($categories['on'] as $key => $label)
                    <label class="row row-switch">
                        <span class="min-w-0 flex-1 font-medium">{{ $label }}</span>
                        <input type="checkbox" name="on[]" value="{{ $key }}" class="switch" @checked(!in_array($key, $off, true)) data-action="change->autosubmit#submit">
                    </label>
                @endforeach
                @foreach ($categories['always'] as $label)
                    <div class="row">
                        <span class="min-w-0 flex-1 font-medium">{{ $label }}</span>
                        <span class="tag">всегда</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        <section>
            <h2 class="list-head">Тихие часы</h2>
            <div class="list">
                <label class="row row-switch">
                    <x-ui.row-icon name="moon"/>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">Без уведомлений на телефон ночью</span>
                        <span class="row-sub"><span class="tag nums">22:00–08:00</span></span>
                    </span>
                    <input type="checkbox" name="quiet" value="1" class="switch" @checked($settings['quiet'] ?? false) data-action="change->autosubmit#submit">
                </label>
            </div>
        </section>
    </form>

    <form method="post" action="/account/notifications/check" class="list">
        @csrf
        <button class="row w-full text-left">
            <x-ui.row-icon name="send" tone="accent"/>
            <span class="min-w-0 flex-1 font-medium">Проверить</span>
        </button>
    </form>
</x-ui.cabinet>
