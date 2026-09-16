{{-- Настройки уведомлений: каналы, о чём, тихие часы — строки с тумблерами, сохраняются сами;
     критичное без тумблера с пометкой «всегда»; внизу «Проверить». --}}
@php
    $push = (bool) config('xcar.vapid.public');
    $off = $settings['off'] ?? [];
@endphp
<x-ui.cabinet title="Настройки уведомлений" :back="['Уведомления', '/account/notifications']">
    <form method="post" action="/account/notifications/settings" class="flex flex-col gap-6" data-controller="autosubmit">
        @csrf @method('put')

        <section>
            <h2 class="text-xl">Каналы</h2>
            <div class="mt-4 flex flex-col gap-2">
                @if ($push)
                    {{-- Пуш — про это устройство, не про аккаунт: тумблером управляет push_controller, не форма. --}}
                    <label class="row row-switch" data-controller="push">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="bell" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">На этот телефон</span>
                            <span class="row-sub text-danger" data-push-target="state"></span>
                        </span>
                        <input type="checkbox" class="switch" data-push-target="toggle" data-action="change->push#toggle">
                    </label>
                @endif
                @if ($user->email)
                    <label class="row row-switch">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="mail" class="size-5"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">На почту</span>
                            <span class="row-sub"><span class="tag">{{ $user->email }}</span></span>
                        </span>
                        <input type="checkbox" name="mail" value="1" class="switch" @checked($user->wantsMail()) data-action="change->autosubmit#submit">
                    </label>
                @endif
            </div>
        </section>

        <section>
            <h2 class="text-xl">О чём</h2>
            <div class="mt-4 flex flex-col gap-2">
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

        <section>
            <h2 class="text-xl">Тихие часы</h2>
            <div class="mt-4 flex flex-col gap-2">
                <label class="row row-switch">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="moon" class="size-5"/></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">Без уведомлений на телефон ночью</span>
                        <span class="row-sub"><span class="tag nums">22:00 — 08:00</span></span>
                    </span>
                    <input type="checkbox" name="quiet" value="1" class="switch" @checked($settings['quiet'] ?? false) data-action="change->autosubmit#submit">
                </label>
            </div>
        </section>
    </form>

    <form method="post" action="/account/notifications/check" class="contents">
        @csrf
        <button class="row w-full text-left">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="send" class="size-5"/></span>
            <span class="min-w-0 flex-1 font-medium">Проверить</span>
        </button>
    </form>
</x-ui.cabinet>
