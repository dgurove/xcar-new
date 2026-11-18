{{-- Шаг «Дополнительная просьба» в пути сделки (18.11.2026, владелец по Z691/046/13570/26: «пока активна доп. просьба, ход сделки
     замораживается — временно этап не „Подтверждение покупки“, а „Дополнительная просьба“»; `Offers\ExtraAsk`). Встаёт на место
     текущего этапа, этап ждёт следом серым без кнопок — как «Переоценка». Строкой — что попросили и письмо; ниже только то, что
     делать сейчас: ждём поставщика («Ответили не письмом» в «⋯»), «Показать менеджеру» и «Вопрос не решён» (ответ пустой —
     «отсутствую на рабочем месте»), менеджер решает — его кнопки за него. Осмотр до «Беру в работу» и после — один модуль (владелец),
     разница — подписи кнопок (`ExtraAsk::labels`). Сделанное — серой строкой, что впереди — «Потом: …». --}}
@props(['ask', 'deal'])
@php
    use App\Offers\ExtraAsk;
    use App\Support\Ago;
    $offer = $deal->offer;
    $n = $offer->number;
    $phase = $ask->phase();
    $buyer = $deal->buyer;
    $labels = ExtraAsk::labels($deal);
    $menu = 'extra-more-'.$ask->id;
    $letters = fn (?int $id) => '/offers/'.$n.'/letters'.($id ? '?at='.$id : '');
    $pending = $phase === ExtraAsk::SHOW && \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->exists();
    $later = array_values(array_filter([
        $ask->asks_manager && $phase === ExtraAsk::SUPPLIER ? 'показать ответ менеджеру' : null,
        $ask->asks_manager && $phase !== ExtraAsk::MANAGER ? ($phase === ExtraAsk::SUPPLIER ? 'его решение' : 'решение менеджера') : null,
    ]));
@endphp
<div class="step step--current">
    <span class="step-dot"></span>
    <div class="step-body">
        <div class="step-head !flex-nowrap !items-start">
            <span class="step-title min-w-0 flex-1">Дополнительная просьба</span>
            <x-ui.more :id="$menu" class="ml-auto mt-0.5 shrink-0">
                <div class="menu-group">
                    @if ($ask->message_id)
                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters($ask->message_id) }}"><x-ui.icon name="mail" class="size-4"/>Письмо с просьбой</button>
                    @endif
                    @if ($phase === ExtraAsk::SUPPLIER)
                        <form method="post" action="/offers/{{ $n }}/extra-asks/{{ $ask->id }}/phone" class="contents" data-turbo-confirm="Поставщик ответил не письмом?" data-turbo-confirm-label="Ответили">@csrf<button class="menu-item w-full" role="menuitem">Ответили не письмом</button></form>
                    @else
                        <form method="post" action="/offers/{{ $n }}/extra-asks/{{ $ask->id }}/reopen" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Вопрос не решён</button></form>
                    @endif
                </div>
            </x-ui.more>
        </div>

        {{-- Что попросили — главной строкой; под ней серым письмо, ответ и сколько ждём — одним рядом. --}}
        <p class="reprice-line">{{ \Illuminate\Support\Str::ucfirst($ask->phrase()) }}</p>
        <p class="reprice-meta nums">
            @if ($ask->message_id)
                <button type="button" class="reprice-letter" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters($ask->message_id) }}">письмо {{ Ago::html($ask->asked_at, words: false) }} ›</button>
            @else
                <span>просьба {{ Ago::html($ask->asked_at, words: false) }}</span>
            @endif
            @if ($phase === ExtraAsk::SUPPLIER)
                <span>ждём ответа {{ Ago::since($ask->since_at, hours: true) }}</span>
            @elseif ($phase === ExtraAsk::MANAGER && $ask->answer_message_id)
                <button type="button" class="reprice-letter" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters($ask->answer_message_id) }}">ответ {{ Ago::html($ask->answered_at, words: false) }} ›</button>
            @elseif ($phase === ExtraAsk::MANAGER)
                <span>ответили не письмом</span>
            @endif
        </p>

        {{-- Что делать сейчас: ответ пришёл — письмо строкой «Показать менеджеру?» и «Вопрос не решён»; показали — менеджер решает. --}}
        @if ($phase === ExtraAsk::SHOW)
            @if ($pending)
                <x-deal.reply-review :deal="$deal" line :manual="false"/>
            @elseif (! $ask->answer_message_id)
                <p class="reprice-done nums">Ответили не письмом, {{ Ago::html($ask->answered_at, words: false) }}</p>
            @endif
            <div class="reprice-acts">
                @unless ($pending)<x-deal.reply-review :deal="$deal" pill/>@endunless
                <form method="post" action="/offers/{{ $n }}/extra-asks/{{ $ask->id }}/reopen" class="contents">@csrf<x-ui.button size="sm" variant="secondary">Вопрос не решён</x-ui.button></form>
            </div>
        @elseif ($phase === ExtraAsk::MANAGER && $buyer)
            <div class="step-who">
                <x-ui.avatar :user="$buyer" :size="32"/>
                <div class="min-w-0">
                    <div class="truncate">{{ $buyer->shortName() }}</div>
                    <x-ui.state tone="plain" class="nums">решает с {{ Ago::at($ask->shown_at, true, words: false) }}</x-ui.state>
                </div>
                {{-- За него — двумя кнопками в ряд, как «Согласен / Отказался» переоценки. --}}
                <div class="step-who-acts">
                    <form method="post" action="/offers/{{ $n }}/extra-asks/decide" data-turbo-confirm="За менеджера: «{{ $labels['take'] }}»?" data-turbo-confirm-label="Да">
                        @csrf<input type="hidden" name="decision" value="take"><x-ui.button size="sm" variant="secondary">Берёт</x-ui.button>
                    </form>
                    <form method="post" action="/offers/{{ $n }}/extra-asks/decide" data-turbo-confirm="За менеджера: «{{ $labels['drop'] }}»?" data-turbo-confirm-text="Сделка отменится, ТС вернётся в продажу" data-turbo-confirm-label="Отменить сделку">
                        @csrf<input type="hidden" name="decision" value="drop"><x-ui.button size="sm" variant="secondary" class="text-danger">Не берёт</x-ui.button>
                    </form>
                </div>
            </div>
        @endif

        @if ($later)
            <p class="reprice-later">Потом: {{ implode(', ', $later) }}</p>
        @endif
    </div>
</div>
