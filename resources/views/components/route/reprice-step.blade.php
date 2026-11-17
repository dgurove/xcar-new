{{-- Шаг «Переоценка» в пути сделки (04.11.2026, владелец про X6: «почему согласование с поставщиком, если идёт переоценка»):
     текущий, этап маршрута ждёт следом серым. С 16.11.2026 решение — прямо в шаге (владелец: «переоценка непонятно сделана»,
     «„Разобрать“ — непонятная формулировка»; выбрал вариант Б макета): фраза «Т-Страхование просит N вместо M», под ней только
     то, что делать сейчас, с кнопками (`Offers\RepriceFlow`): цена — «Принять…» (шторка `x-offer.reprice`) и «Отклонить»;
     менеджер сделки — строкой с аватаром и его ответом (`x-offer.reprice-ask-acts`), публикация для других — «Снять
     публикацию»; ответ поставщику — «Ответить» с готовым текстом. Сделанное — серой строкой, что впереди — «Потом: …». --}}
@props(['flow'])
@php
    use App\Offers\RepriceFlow;
    use App\Support\{Ago, Money};
    $offer = $flow->offer;
    $n = $offer->number;
    $reprice = $flow->reprice;
    $ask = $flow->ask;
    $buyer = $flow->deal->buyer;
    $letter = $reprice->message;
    $price = $flow->price();
    $taker = $flow->buyer();
    $answer = $flow->answer();
    $menu = 'reprice-more-'.$reprice->id;
    $letters = '/offers/'.$n.'/letters'.($letter ? '?at='.$letter->id : '');
    // Прежняя оценочная известна, пока переоценка ждёт: после «Принять» у предложения уже новая.
    $valueWas = $reprice->isPending() && $offer->value && $offer->value !== $reprice->value ? (int) $offer->value : null;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::find($offer->vendor_id) : null;
    $who = $reprice->source === 'letter' && $vendor ? $vendor->name.' просит' : 'Новая закупочная';
    $later = array_values(array_filter([
        $taker === RepriceFlow::WAIT ? 'спросить менеджера' : null,
        $answer === RepriceFlow::WAIT ? 'ответить поставщику' : null,
    ]));
@endphp
<div class="step step--current">
    <span class="step-dot"></span>
    <div class="step-body">
        <div class="step-head !flex-nowrap !items-start">
            <span class="step-title min-w-0 flex-1">Переоценка</span>
            @if ($letter || ($answer && $answer !== RepriceFlow::DONE))
                <x-ui.more :id="$menu" class="ml-auto mt-0.5 shrink-0">
                    <div class="menu-group">
                        @if ($letter)
                            <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}"><x-ui.icon name="mail" class="size-4"/>Письмо поставщика</button>
                        @endif
                        @if ($answer && $answer !== RepriceFlow::DONE)
                            <form method="post" action="/offers/{{ $n }}/reprices/{{ $reprice->id }}/answered" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Ответили не письмом</button></form>
                        @endif
                    </div>
                </x-ui.more>
            @endif
        </div>

        <p class="reprice-line nums">{{ $who }} <b>{{ Money::rub($reprice->to_price) }}</b> вместо <s>{{ Money::nums((int) $reprice->from_price) }}</s></p>
        <p class="reprice-meta nums">
            @if ($reprice->value)<span>оценочная @if ($valueWas)<s>{{ Money::nums($valueWas) }}</s> @endif{{ Money::rub($reprice->value) }}</span>@endif
            @if ($letter)
                <button type="button" class="reprice-letter" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}">письмо {{ Ago::html($letter->date_at, words: false) }} ›</button>
            @else
                <span>{{ $reprice->sourceLabel() }}</span>
            @endif
        </p>

        {{-- Сделанное — серой строкой. --}}
        @if ($price === RepriceFlow::DONE)
            <p class="reprice-done nums">Цену {{ $reprice->decider ? 'решил '.$reprice->decider->shortName() : 'решили' }} {{ Ago::html($reprice->decided_at, words: false) }}</p>
        @endif
        @if ($taker === RepriceFlow::DONE && $ask && ! $ask->isOpen() && $buyer)
            {{-- Гаражная — согласие на закупочную, цены подтверждения у неё нет (`RepriceAsk::agreesOnly`, 17.11.2026). --}}
            <p class="reprice-done nums">{{ $buyer->shortName() }} согласен на {{ $ask->agreesOnly() ? 'закупочную '.Money::rub($ask->to_price) : Money::rub((int) $flow->deal->amount) }}{{ $ask->answered_at ? ', '.Ago::at($ask->answered_at, words: false) : '' }}</p>
        @endif

        {{-- Что делать сейчас. --}}
        @if ($price === RepriceFlow::NOW)
            <div class="reprice-acts">
                <x-ui.button type="button" size="sm" data-controller="emit" data-action="emit#send" data-emit-event-param="reprice:open">Принять…</x-ui.button>
                <form method="post" action="/offers/{{ $n }}/reprices/{{ $reprice->id }}" class="contents" data-turbo-confirm="Отклонить переоценку? Закупочная останется {{ Money::rub((int) $reprice->from_price) }}" data-turbo-confirm-label="Отклонить">
                    @csrf @method('delete')<x-ui.button size="sm" variant="secondary">Отклонить</x-ui.button>
                </form>
            </div>
        @endif
        @if ($taker === RepriceFlow::NOW && $buyer)
            <div class="step-who">
                <x-ui.avatar :user="$buyer" :size="32"/>
                <div class="min-w-0">
                    <div class="truncate">{{ $buyer->shortName() }}</div>
                    @if ($ask?->isOffered())
                        <x-ui.state tone="urgent" class="nums">предлагает {{ Money::rub((int) $ask->offered_price) }}{{ $ask->counter_price ? ', от нашего отказался' : '' }}</x-ui.state>
                    @elseif ($ask?->isCountered())
                        <x-ui.state :tone="$ask->late() ? 'danger' : 'accent'" class="nums">наше {{ Money::rub((int) $ask->counter_price) }} вместо {{ Money::nums((int) $ask->offered_price) }}, {{ $ask->late() ? 'не ответил, срок ' : 'думает до ' }}{{ Ago::at($ask->due_at, true, words: false) }}</x-ui.state>
                    @elseif ($ask?->isPending())
                        <x-ui.state :tone="$ask->late() ? 'danger' : 'plain'" class="nums">{{ $ask->late() ? ($ask->agreesOnly() ? 'не ответил, срок ' : 'не назвал цену, срок ') : 'думает до ' }}{{ Ago::at($ask->due_at, true, words: false) }}</x-ui.state>
                    @endif
                </div>
                @if ($ask?->isOpen())
                    <x-offer.reprice-ask-acts class="step-who-acts" :ask="$ask" :offer="$offer"/>
                @endif
            </div>
        @endif
        @if ($price === RepriceFlow::DONE && $flow->showsToAll())
            @php $publishedTo = \App\Offers\AudienceRules::summary(\App\Offers\AudienceRules::forDeal($offer)); @endphp
            <div class="step-who">
                <span class="step-who-icon"><x-ui.icon name="users" class="size-4"/></span>
                <button type="button" class="min-w-0 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-publish:open">
                    <span class="block">{{ $publishedTo }}</span>
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <x-ui.state tone="accent" class="nums">видят до {{ Ago::at($offer->deal_show_until, true, words: false) }}</x-ui.state>
                        @if ($flow->reserves)<span class="tag nums">{{ $flow->reserves }} {{ \App\Support\Plural::of($flow->reserves, ['подтверждение', 'подтверждения', 'подтверждений']) }}</span>@endif
                    </span>
                </button>
                <form method="post" action="/offers/{{ $n }}/deal-publication" class="step-who-acts" data-turbo-confirm="{{ \App\Offers\Actions\PublishDeal::STOP_CONFIRM }}" data-turbo-confirm-text="{{ \App\Offers\Actions\PublishDeal::STOP_TEXT }}" data-turbo-confirm-label="Снять">
                    @csrf @method('delete')<button type="submit" class="btn btn-s btn-quiet shrink-0 !h-8 rounded-full !px-3.5">Снять публикацию</button>
                </form>
            </div>
        @endif
        @if ($answer === RepriceFlow::NOW)
            <p class="reprice-line nums">{{ $flow->rise() ? 'Предлагаем' : 'Согласны на' }} <b>{{ Money::rub($flow->agreedAmount()) }}</b>@if ($flow->rise()) <span class="text-ink-muted">({{ Money::nums($reprice->value) }} {{ $flow->rise() }})</span>@endif</p>
            @if ($url = $flow->replyUrl())
                <div class="reprice-acts"><x-mail.window-button :url="$url" label="Ответить поставщику" icon="reply" class="btn-s btn-accent"/></div>
            @endif
        @endif

        @if ($later)
            <p class="reprice-later">Потом: {{ implode(', ', $later) }}</p>
        @endif
    </div>
</div>
