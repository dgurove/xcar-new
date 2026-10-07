{{-- Замок менеджера (07.10.2026, Access\Lock): пилюля с обратным отсчётом справа сверху и окно подтверждения поверх
     всего. Один узел на весь сеанс (data-turbo-permanent): переходы его не трогают, состояние и конец окна каждая
     страница приносит в meta lock (layout). Окно открывается само, когда окно работы кончилось (экран под ним стирается,
     lock_controller) или сервер ответил 423; по нажатию на пилюлю — продлить заранее, без стирания. Шаги: code — код из
     Telegram или «Продолжить» в боте; link — привязать Telegram, без него дальше нельзя. --}}
@php
    $user = auth()->user();
    $bot = app(\App\Telegram\Bot::class);
    $name = $bot->username();
    $start = $user->telegram_chat_id === null && $name ? \App\Telegram\StartLink::link($user) : null;
@endphp
<div id="lock" class="contents" data-turbo-permanent data-controller="lock"
    data-lock-bot-app-value="tg://resolve?domain={{ $name }}" data-lock-bot-web-value="https://t.me/{{ $name }}"
    data-lock-minutes-value="{{ \App\Users\Access\Lock::minutes() }}"
    @if ($start) data-lock-link-app-value="tg://resolve?domain={{ $name }}&start={{ $start }}" data-lock-link-web-value="{{ $bot->startUrl($start) }}" @endif>
    <button type="button" class="lock-pill nums" data-lock-target="pill" data-action="lock#renew" hidden aria-label="Осталось до подтверждения"></button>

    <dialog class="lock" data-lock-target="dialog" data-action="cancel->lock#cancel" aria-labelledby="lock-title">
        <div class="lock-step" data-lock-target="step" data-step="code">
            <div class="lock-mark"><x-telegram.logo class="size-full"/></div>
            <h2 id="lock-title" class="text-lg" data-lock-target="title">Продолжить работу</h2>
            <form class="w-full" data-action="submit->lock#submit" novalidate>
                <input class="field-input lock-code" data-lock-target="input" data-action="input->lock#typed" name="code"
                    inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" enterkeyhint="go"
                    autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="Код из Telegram" aria-label="Код из Telegram">
            </form>
            <p class="lock-note" data-lock-target="note" aria-live="polite"></p>
            <div class="flex w-full flex-col gap-1">
                <button type="button" class="btn btn-telegram w-full" data-action="lock#openBot"><x-telegram.logo plain/>Открыть Telegram</button>
                <button type="button" class="btn btn-ghost w-full" data-lock-target="resend" data-action="lock#resend">Отправить ещё раз</button>
                <button type="button" class="btn btn-ghost w-full" data-lock-target="close" data-action="lock#close" hidden>Отмена</button>
                <form method="post" action="/logout" data-turbo="false" data-lock-target="logout">@csrf<button type="submit" class="btn btn-ghost btn-s w-full text-ink-dim">Выйти</button></form>
            </div>
        </div>

        <div class="lock-step" data-lock-target="step" data-step="link" hidden>
            <div class="lock-mark" data-lock-target="linkMark"><x-telegram.logo class="size-full"/></div>
            <h2 class="text-lg">Подключите Telegram</h2>
            <p class="lock-note" data-lock-target="linkNote" aria-live="polite"></p>
            <div class="flex w-full flex-col gap-1">
                @if ($start)
                    <button type="button" class="btn btn-telegram w-full" data-action="lock#link"><x-telegram.logo plain/>Подключить Telegram</button>
                    <div class="tg-qr mt-2 hidden sm:block">{!! \App\Support\Qr::svg($bot->startUrl($start)) !!}</div>
                @endif
                <form method="post" action="/logout" data-turbo="false">@csrf<button type="submit" class="btn btn-ghost btn-s w-full text-ink-dim">Выйти</button></form>
            </div>
        </div>
    </dialog>
</div>
