{{-- Шаг «Переоценка» в пути сделки (04.11.2026, владелец про X6: «почему согласование с поставщиком, если идёт переоценка»;
     «тупо текст — это не дизайн»): текущий, этап маршрута ждёт впереди. Сверху откуда (вендор, письмо) и цены было → стало,
     ниже вложенная лента из трёх подпунктов `Offers\RepriceFlow`: цена (разобрать шторкой плашки), покупатель (менеджер сделки
     строкой с аватаром, его новая цена и решение по ней — `x-offer.reprice-ask-acts`, 10.11.2026; «Все менеджеры», пока показываем, с числом подтверждений
     и «Снять показ»), ответ поставщику (окно писем с готовым текстом, отвечаем, когда есть покупатель). --}}
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
    $answer = $flow->answer();
    $menu = 'reprice-more-'.$reprice->id;
    $letters = '/offers/'.$n.'/letters'.($letter ? '?at='.$letter->id : '');
    // Прежняя оценочная известна, пока переоценка ждёт: после «Применить» у предложения уже новая.
    $valueWas = $reprice->isPending() && $offer->value && $offer->value !== $reprice->value ? (int) $offer->value : null;
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::find($offer->vendor_id) : null;
@endphp
<div class="step step--current">
    <span class="step-dot"></span>
    <div class="step-body">
        <div class="step-head !flex-nowrap !items-start">
            <span class="step-title min-w-0 flex-1">Переоценка</span>
            <div class="contents" data-controller="menu">
                <button type="button" class="btn btn-s btn-quiet btn-round -my-1 ml-auto shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="{{ $menu }}"><x-ui.icon name="more" class="size-5"/></button>
                <div id="{{ $menu }}" class="menu" popover data-menu-target="list" role="menu">
                    @if ($letter)
                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}"><x-ui.icon name="mail" class="size-4"/>Письмо поставщика</button>
                    @endif
                    @if ($answer && $answer !== RepriceFlow::DONE)
                        <form method="post" action="/offers/{{ $n }}/reprices/{{ $reprice->id }}/answered" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Ответили не письмом</button></form>
                    @endif
                    @if ($reprice->isPending())
                        <form method="post" action="/offers/{{ $n }}/reprices/{{ $reprice->id }}" class="contents" data-turbo-confirm="Отклонить переоценку? Закупочная останется {{ Money::rub((int) $reprice->from_price) }}" data-turbo-confirm-label="Отклонить">
                            @csrf @method('delete')<button class="menu-item w-full text-danger" role="menuitem">Отклонить переоценку</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Откуда: вендор и письмо ссылкой в окно писем на нём. --}}
        <div class="reprice-from">
            @if ($vendor)<x-vendor.name :vendor="$vendor"/>@endif
            @if ($letter)
                <button type="button" class="nums underline decoration-dotted underline-offset-4" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}">письмо {{ Ago::html($letter->date_at, words: false) }}</button>
            @else
                <span>{{ $reprice->sourceLabel() }}</span>
            @endif
        </div>

        {{-- Было → стало: прежняя зачёркнута, новая закупочная — полужирным. --}}
        <dl class="reprice-prices">
            @if ($reprice->value)
                <dt>Оценочная</dt>
                <dd class="nums">@if ($valueWas)<s>{{ Money::nums($valueWas) }}</s>@endif<span>{{ Money::rub($reprice->value) }}</span></dd>
            @endif
            <dt>Закупочная</dt>
            <dd class="nums"><s>{{ Money::nums((int) $reprice->from_price) }}</s><span class="font-semibold">{{ Money::rub($reprice->to_price) }}</span></dd>
        </dl>

        <ol class="substeps">
            {{-- 1. Цена. --}}
            @php $s = $flow->price(); @endphp
            <li class="substep substep--{{ $s }}">
                <span class="substep-dot">@if ($s === RepriceFlow::DONE)<x-ui.icon name="check" class="size-2.5"/>@endif</span>
                <div class="substep-body">
                    <div class="substep-head">
                        <span class="substep-title">Цена</span>
                        @if ($s === RepriceFlow::DONE)<span class="substep-meta">{{ $reprice->decider?->shortName() }} · <span class="nums">{{ Ago::html($reprice->decided_at, words: false) }}</span></span>@endif
                    </div>
                    @if ($s === RepriceFlow::NOW)
                        <x-ui.button type="button" size="sm" class="mt-2" data-controller="emit" data-action="emit#send" data-emit-event-param="reprice:open">Разобрать</x-ui.button>
                    @endif
                </div>
            </li>

            {{-- 2. Кто берёт по новой цене (05.11.2026: «Покупатель» читался как клиент менеджера): менеджер сделки и, пока он думает, все менеджеры. --}}
            @php $s = $flow->buyer(); @endphp
            <li class="substep substep--{{ $s }}">
                <span class="substep-dot">@if ($s === RepriceFlow::DONE)<x-ui.icon name="check" class="size-2.5"/>@endif</span>
                <div class="substep-body">
                    <div class="substep-head"><span class="substep-title">Кто берёт</span></div>
                    @if ($s !== RepriceFlow::WAIT && $buyer)
                        <div class="step-who">
                            <x-ui.avatar :user="$buyer" :size="32"/>
                            <div class="min-w-0">
                                <div class="truncate">{{ $buyer->shortName() }}</div>
                                @if ($ask?->isOffered())
                                    <x-ui.state tone="urgent" class="nums">предлагает {{ Money::rub((int) $ask->offered_price) }}{{ $ask->counter_price ? ', от нашего отказался' : '' }}</x-ui.state>
                                @elseif ($ask?->isCountered())
                                    <x-ui.state :tone="$ask->late() ? 'danger' : 'accent'" class="nums">наше {{ Money::rub((int) $ask->counter_price) }} вместо {{ Money::nums((int) $ask->offered_price) }}, {{ $ask->late() ? 'не ответил, срок ' : 'думает до ' }}{{ Ago::at($ask->due_at, true, words: false) }}</x-ui.state>
                                @elseif ($ask?->isPending())
                                    <x-ui.state :tone="$ask->late() ? 'danger' : 'urgent'" class="nums">{{ $ask->late() ? 'не назвал цену, срок ' : 'думает до ' }}{{ Ago::at($ask->due_at, true, words: false) }}</x-ui.state>
                                @elseif ($ask)
                                    <x-ui.state tone="open" class="nums">{{ Money::rub((int) $flow->deal->amount) }} · {{ Ago::at($ask->answered_at, words: false) }}</x-ui.state>
                                @else
                                    <x-ui.state class="nums">закупочная {{ Money::nums((int) $flow->deal->cost) }}</x-ui.state>
                                @endif
                            </div>
                            @if ($ask?->isOpen())
                                <x-offer.reprice-ask-acts class="step-who-acts" :ask="$ask" :offer="$offer"/>
                            @endif
                        </div>
                    @endif
                    @if ($flow->showsToAll())
                        <div class="step-who">
                            <span class="step-who-icon"><x-ui.icon name="users" class="size-4"/></span>
                            <div class="min-w-0">
                                <div>Все менеджеры</div>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <x-ui.state tone="accent" class="nums">видят до {{ Ago::at($offer->deal_show_until, true, words: false) }}</x-ui.state>
                                    @if ($flow->reserves)<span class="tag nums">{{ $flow->reserves }} {{ \App\Support\Plural::of($flow->reserves, ['подтверждение', 'подтверждения', 'подтверждений']) }}</span>@endif
                                </div>
                            </div>
                            <form method="post" action="/offers/{{ $n }}/show-all" class="step-who-acts" data-turbo-confirm="Убрать из предложений у всех менеджеров?" data-turbo-confirm-label="Снять показ">
                                @csrf @method('delete')<button type="submit" class="btn btn-s btn-quiet shrink-0 !h-8 rounded-full !px-3.5">Снять показ</button>
                            </form>
                        </div>
                    @endif
                </div>
            </li>

            {{-- 3. Ответ поставщику — только у переоценки письмом; отвечаем, когда есть покупатель. --}}
            @if ($answer)
                <li class="substep substep--{{ $answer }}">
                    <span class="substep-dot">@if ($answer === RepriceFlow::DONE)<x-ui.icon name="check" class="size-2.5"/>@endif</span>
                    <div class="substep-body">
                        <div class="substep-head">
                            <span class="substep-title">Ответ поставщику</span>
                            @if ($answer === RepriceFlow::WAIT)<span class="substep-meta">когда кто-то возьмёт</span>@endif
                        </div>
                        @if ($answer === RepriceFlow::NOW)
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-2">
                                <span class="nums text-sm text-ink-muted">{{ $flow->rise() ? 'Предлагаем' : 'Согласны на' }} <span class="text-ink">{{ Money::rub($flow->agreedAmount()) }}</span>@if ($flow->rise()) ({{ Money::nums($reprice->value) }} {{ $flow->rise() }})@endif</span>
                                @if ($url = $flow->replyUrl())
                                    <x-mail.window-button :url="$url" label="Ответить" icon="reply" class="btn-s btn-accent"/>
                                @endif
                            </div>
                        @endif
                    </div>
                </li>
            @endif
        </ol>
    </div>
</div>
