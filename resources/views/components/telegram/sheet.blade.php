{{-- Привязка Telegram шторкой: окошко в оболочке (`x-telegram.offer`) и строка профиля.
     «Привязать» уводит в бота ссылкой со /start, страница ждёт (telegram_controller); на ПК рядом QR той же ссылки.
     later — кнопка «Напомнить позже» (только у окошка), shown — окошко показали сами. --}}
@props(['id' => 'telegram', 'open' => false, 'later' => false, 'shown' => false])
@php
    $user = auth()->user();
    $url = app(\App\Telegram\Bot::class)->startUrl(\App\Telegram\StartLink::link($user));
@endphp
@if ($url)
<x-ui.sheet :id="$id" :open="$open">
    <div class="flex flex-col gap-5" data-controller="telegram" data-telegram-mode-value="link" @if ($shown) data-telegram-shown-value="true" @endif>
        <div class="flex flex-col items-center gap-3 text-center">
            <span class="flex size-16 items-center justify-center rounded-full bg-surface-3 text-ink"><x-ui.icon name="telegram" class="size-8"/></span>
            <h2 class="text-lg">Привяжите Telegram</h2>
        </div>
        <div class="list">
            @if ($user->isManager())
                <div class="row"><x-ui.icon name="deal" class="size-5 shrink-0 text-ink-muted"/><span class="min-w-0 flex-1">Ваш ход и деньги по сделкам</span></div>
            @else
                <div class="row"><x-ui.icon name="bell" class="size-5 shrink-0 text-ink-muted"/><span class="min-w-0 flex-1">Счета, оплаты и выплаты с кнопками</span></div>
            @endif
            <div class="row"><x-ui.icon name="key" class="size-5 shrink-0 text-ink-muted"/><span class="min-w-0 flex-1">Вход без пароля</span></div>
        </div>
        {{-- С компьютера Telegram обычно в телефоне: камерой по QR. --}}
        <div class="mx-auto hidden w-44 rounded-(--radius-l) bg-white p-3 text-black sm:block [&>svg]:block [&>svg]:h-auto [&>svg]:w-full">{!! \App\Support\Qr::svg($url) !!}</div>
        <div class="flex flex-col gap-2">
            <a href="{{ $url }}" target="_blank" rel="noopener" data-turbo="false" class="btn btn-accent w-full" data-action="telegram#wait"><span data-telegram-target="label">Привязать</span></a>
            @if ($later)<button type="button" class="btn btn-ghost w-full" data-action="telegram#later">Напомнить позже</button>@endif
        </div>
    </div>
</x-ui.sheet>
@endif
