{{-- Карточка вверху «Сделок»: подключить Telegram, пока не привязан и не скрыли (× — на 30 дней). Вся карточка открывает шторку. --}}
@php
    $user = auth()->user();
    $show = $user && $user->canLinkTelegram() && in_array('card', $user->telegramMoments(), true);
@endphp
@if ($show)
    <div class="list">
        <div class="row gap-3">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="telegram:open">
                <x-telegram.logo class="size-10"/>
                <span class="min-w-0 flex-1">
                    <span class="block">Сделки в Telegram</span>
                    <span class="block text-sm text-ink-muted">Узнавайте первым, что выбрали вас</span>
                </span>
                {{-- На телефоне кнопкой служит вся карточка: пилюля съедала текст. --}}
                <span class="btn btn-s btn-telegram shrink-0 !h-8 rounded-full !px-3.5 max-sm:hidden">Подключить</span>
            </button>
            <form method="post" action="/account/telegram/seen" class="contents">
                @csrf<input type="hidden" name="moment" value="card">
                <button class="sheet-close -mr-1" aria-label="Скрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
            </form>
        </div>
    </div>
@endif
