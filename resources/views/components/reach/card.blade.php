{{-- «Не пропускайте вывозы / заявки» — над списком логистики и парковки (20.10.2026: у логиста Татьяны не было ни Telegram,
     ни пушей, уведомления лежали только в колокольчике). Две строки — каждая своя кнопка: Telegram (шторка
     x-telegram.connect) и пуши на это устройство (push_controller в режиме строки: нет Web Push или уже подписан — строки
     нет, запретил — подсказка вместо кнопки; «Включить» — окно x-push.offer). Обе ушли — карточки нет (reach_controller). «×» — «Больше не предлагать?»:
     навсегда или через неделю (`User::offersReach`). --}}
@props(['title'])
@php
    $user = auth()->user();
    $telegram = $user?->canLinkTelegram() ?? false;
    $push = (bool) config('xcar.vapid.public');
@endphp
@if ($user?->offersReach(request()->boolean('reach')) && ($telegram || $push))
    <section class="mt-4" data-controller="reach" @unless ($telegram) hidden @endunless>
        <div class="list-head flex items-center justify-between">
            <span>{{ $title }}</span>
            <button type="button" class="sheet-close -mr-1" aria-label="Скрыть" data-action="reach#dismiss"><x-ui.icon name="x" class="size-[18px]"/></button>
        </div>
        <div class="list">
            @if ($telegram)
                <button type="button" class="row w-full gap-3 text-left" data-reach-row data-controller="emit" data-action="emit#send" data-emit-event-param="telegram:open">
                    <x-telegram.logo class="size-8 shrink-0"/>
                    <span class="min-w-0 flex-1">В Telegram</span>
                    <span class="btn btn-s btn-telegram shrink-0 !h-8 rounded-full !px-3.5">Подключить</span>
                </button>
            @endif
            @if ($push)
                <div class="contents" data-reach-row hidden data-controller="push" data-push-row-value="true">
                    <div class="row gap-3">
                        <x-ui.row-icon name="bell"/>
                        <span class="min-w-0 flex-1">
                            <span class="block">На это устройство</span>
                            <span class="row-sub text-danger" data-push-target="state" hidden></span>
                        </span>
                        <button type="button" class="btn btn-s btn-accent shrink-0 !h-8 rounded-full !px-3.5" data-push-target="on" data-controller="emit" data-action="emit#send" data-emit-event-param="push:open">Включить</button>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif
