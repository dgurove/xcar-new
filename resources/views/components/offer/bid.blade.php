{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, когда подтвердил — справа в строке суммы, светофор
     строки (не ниже порога — сумма зелёным, ниже — жёлтая заливка, 26.10.2026; строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) листом «Отдать» с этим подтверждением (`x-offer.give-sheet`) и «Отклонить» рядом — одинаково в
     редакторе и карточке строки. Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже. --}}
@props(['bid', 'offer', 'parked' => collect(), 'deal' => null, 'chats' => null, 'dealUrl' => null])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $garage = $bid->isGarage();
    // Принятое — цена самой сделки (10.11.2026, владелец: «почему сумма сделки и подтверждения — две разные суммы»): копия
    // в подтверждении не может разойтись с тем, по чему счета и договор.
    $price = $deal && ! $garage && $deal->amount !== null ? (int) $deal->amount : $bid->amount;
    $diff = $offer->asking_price && ! $garage ? $price - $offer->asking_price : null;
    // Светофор строки (26.10.2026): ниже порога — жёлтым, без слов (`Offer::bidZone`).
    $zone = $active && ! $garage && $bid->amount ? $offer->bidZone($bid->amount) : 'ok';
    $low = in_array($zone, ['low', 'bad'], true);
    // Вдвое выше цены продажи — лишний ноль? Красным словом: принять такое — счёт на сумму в разы больше (02.11.2026).
    $high = $zone === 'high';
    // Не выше закупочной (14.10.2026, 3308 приняли в минус молча): красным в строке, «Принять» переспрашивает, и сервер
    // без согласия такую не примет (`Give`, `loss`).
    // Закупочная, с которой менеджер подтверждал (`bids.cost`, 15.10.2026), — она закрепится в сделке.
    $floor = $bid->frozenCost();
    $under = ! $garage && $floor && $price !== null ? $floor - $price : null;
    $loss = $under !== null && $under >= 0;
    $give = (bool) $offer->deal;
    // «Отдать…» (07.11.2026): у сделки покупателя окно спрашивает — передать полностью или поручить параллельно.
    $twoWays = $give && ! $garage && ! $offer->deal->isGarage() && ! $offer->deal->isAuction();
    $inGarage = $garage ? (int) ($parked[$bid->user_id] ?? 0) : 0;
@endphp
@php
    $n = $bid->id;
    $accepted = $bid->state === BidState::Accepted;
    $counter = $bid->hasPendingCounter();
    // «Изменить…» (22.11.2026; прежде «Поправить цену…» только у ценового): цена и вид «Для покупателя» ↔ «В гараж» — у любого
    // живого ценового или гаражного (`x-offer.bid-revise`, `ReviseBid`).
    $fix = $active && in_array($bid->kind, [\App\Offers\BidKind::Buyer, \App\Offers\BidKind::Garage], true);
    // Контрпредложение — и резерву при идущей сделке (19.11.2026, владелец): согласится — сделка сразу его (`AnswerCounter`).
    $haggle = $active && ! $garage;
    $who = $bid->user;
    $talk = $who->hasDeals() && ($active || $accepted);
    $sheet = $active || $accepted;
    $diffText = $diff ? ($diff > 0 ? '+' : '−').Money::nums(abs($diff)) : null;
    $alerts = $high || ($loss && $active) || (! $active && ! $accepted) || $counter || $bid->counterDeclined();
    // На чём сошлись (24.11.2026, владелец: «кто решил — видно, что решили — нет»): отказался от контрпредложения — «отказался от N»
    // вместо голого «отозвано»; согласился — «по контрпредложению, было N».
    $refusedCounter = $bid->counterDeclined();
    $wasBefore = $accepted || $active ? $bid->amountBeforeCounter() : null;
    $byCounter = $bid->counter_state === \App\Offers\CounterState::Accepted;
@endphp
{{-- Строка (19.11.2026, владелец по макетам, вариант 3: «всё в кучу свалено», «кнопки слева — бред»; 20.11.2026, вариант Б: «имя
     сверху, сумма под именем слева, дата и время справа» — имя рвалось по буквам): имя на всю ширину, ниже цена светофором
     слева и когда справа. Разница, «в гараже», «внёс» и подписи тревог («ниже закупочной на …» — только красной суммой) — в шторке;
     в строке — лишь контрпредложение и «отклонено». Кнопок в строке нет:
     нажатие — шторка (`x-ui.more`): шапка с ценой и комментарием, «Написать» и главная «Принять…» / «Отдать…» в ряд, ниже
     списком «Изменить…» (цена и вид, 22.11.2026), «Контрпредложение…», ответ за менеджера и красное «Отклонить…». Отклонённые — серой строкой. --}}
<div @class(['bid-line', 'bid-row--loss' => ($loss && $active) || $high, 'bid-row--low' => $low && ! ($loss && $active), 'bid-row--ok' => $active && ! $garage && ! $low && ! $loss && ! $high, 'bid-line--off' => ! $sheet])>
    @php ob_start(); @endphp
        <span class="bid-amount nums font-semibold">{{ $garage ? 'В гараж' : Money::rub($price) }}</span>
        @if ($diffText)<span class="bid-line-diff nums">{{ $diffText }}</span>@elseif ($inGarage)<span class="bid-line-diff nums">в гараже {{ $inGarage }}</span>@endif
    @php $sum = ob_get_clean(); ob_start(); @endphp
        @if ($high)<x-ui.state tone="danger">больше чем вдвое выше цены, проверьте сумму</x-ui.state>@endif
        @if ($loss && $active)<x-ui.state tone="danger">{{ $under > 0 ? 'ниже закупочной на '.Money::nums($under) : 'равна закупочной' }}</x-ui.state>@endif
        {{-- Принятое — под подписью «Принято», словом не повторяется. --}}
        @if (! $active && ! $accepted && ! $refusedCounter)<x-ui.state :tone="$bid->state === BidState::Declined ? 'danger' : 'closed'">{{ mb_strtolower($bid->state->label()) }}</x-ui.state>@endif
        @if ($counter)<x-ui.state tone="accent"><span class="nums">→ {{ Money::rub($bid->counter_amount) }}</span>, ждём ответа</x-ui.state>@if ($bid->counter_remind_at)<span>напомним {!! \App\Support\Ago::html($bid->counter_remind_at, true, words: false) !!}</span>@endif
        @elseif ($refusedCounter)<span>отказался от контрпредложения <span class="nums">{{ Money::rub($bid->counter_amount) }}</span></span>
        @elseif ($byCounter)<span>по контрпредложению{{ $wasBefore ? ', было ' : '' }}@if ($wasBefore)<span class="nums">{{ Money::nums($wasBefore) }}</span>@endif</span>@endif
    @php $alert = ob_get_clean(); $rowAlert = (! $active && ! $accepted) || $counter || $byCounter || ($bid->extra_picks && $active); @endphp
    @php ob_start(); @endphp
        <x-ui.avatar :user="$who" :size="36"/>
        <span class="min-w-0 flex-1">
            <span class="bid-line-name">{{ $who->shortName() }}</span>
            <span class="bid-line-row">
                <span class="bid-amount nums font-semibold">{{ $garage ? 'В гараж' : Money::rub($price) }}</span>
                <span class="bid-line-when nums">{{ \App\Support\Ago::html($bid->created_at, true, words: false) }}</span>
            </span>
            @if ($rowAlert)
                <span class="bid-line-sub">
                    @if ($refusedCounter)<x-ui.state tone="closed">отказался от <span class="nums">{{ Money::rub($bid->counter_amount) }}</span></x-ui.state>
                    @elseif (! $active && ! $accepted)<x-ui.state :tone="$bid->state === BidState::Declined ? 'danger' : 'closed'">{{ mb_strtolower($bid->state->label()) }}</x-ui.state>@endif
                    @if ($counter)<x-ui.state tone="accent"><span class="nums">→ {{ Money::rub($bid->counter_amount) }}</span>, ждём ответа</x-ui.state>@if ($bid->counter_remind_at)<span>напомним {!! \App\Support\Ago::html($bid->counter_remind_at, true, words: false) !!}</span>@endif
                    @elseif ($byCounter)<span>по контрпредложению{{ $wasBefore ? ', было ' : '' }}@if ($wasBefore)<span class="nums">{{ Money::nums($wasBefore) }}</span>@endif</span>@endif
                    @if ($bid->extra_picks && $active)<span>и запросить {{ \App\Offers\ExtraWant::wordsOf($bid->extra_picks, lower: true) }}</span>@endif
                </span>
            @endif
        </span>
        @if ($sheet)<x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-muted"/>@endif
    @php $main = ob_get_clean(); @endphp
    @if ($sheet)
        <x-ui.more :id="'bid-menu-'.$n" sheet-class="bid-sheet">
            <x-slot:trigger><button type="button" class="bid-line-main" data-action="sheet#open" aria-haspopup="dialog" aria-controls="bid-menu-{{ $n }}">{!! $main !!}</button></x-slot:trigger>
            <div class="bid-sheet-head">
                <div class="flex items-center gap-3">
                    <x-ui.avatar :user="$who" :size="40"/>
                    <div class="min-w-0 flex-1">
                        <div class="bid-line-name">{{ $who->shortName() }}@if ($who->isSuperBuyer()) <span class="text-sm font-normal text-ink-dim">супер-покупатель</span>@endif</div>
                        <div class="text-sm text-ink-dim nums">{{ \App\Support\Ago::html($bid->created_at, true, words: false) }}</div>
                    </div>
                    <span class="bid-line-end">{!! $sum !!}</span>
                </div>
                @if ($alerts)<div class="bid-line-sub mt-2">{!! $alert !!}</div>@endif
                @if ($bid->comment)<p class="mt-2 text-sm">{{ $bid->comment }}</p>@endif
                {{-- Просит доп. запрос, если примут (27.11.2026, `bids.extra_picks`) — видно при выборе, кого принять. --}}
                @if ($bid->extra_picks)<p class="mt-2 text-sm">Просит запросить: {{ \App\Offers\ExtraWant::wordsOf($bid->extra_picks, lower: true) }}</p>@endif
                @if ($bid->byStaff())<p class="mt-1 text-sm text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</p>@endif
                @if ($talk || $active || $dealUrl)
                    <div class="bid-sheet-acts">
                        @if ($talk)<x-offer.chat-button :offer="$offer" :user="$who" :chats="$chats"/>@endif
                        @if ($active)
                            <button type="button" class="btn btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send sheet#close" data-emit-event-param="give-bid-{{ $n }}:open">{{ $give ? 'Отдать…' : 'Принять…' }}</button>
                        @elseif ($dealUrl)
                            <a href="{{ $dealUrl }}" class="btn btn-quiet min-w-0 flex-1" data-turbo-frame="_top">Перейти к сделке</a>
                        @endif
                    </div>
                @endif
            </div>
            @if ($fix || $haggle)
                <div class="menu-group">
                    @if ($fix)<button type="button" class="menu-item" data-controller="emit" data-action="emit#send" data-emit-event-param="bid-revise-{{ $n }}:open"><x-ui.icon name="edit" class="size-5 text-ink-muted"/>Изменить…</button>@endif
                    @if ($haggle)<button type="button" class="menu-item" data-controller="emit" data-action="emit#send" data-emit-event-param="counter-{{ $n }}:open"><x-ui.icon name="refresh" class="size-5 text-ink-muted"/>{{ $counter ? 'Другая цена…' : 'Контрпредложение…' }}</button>@endif
                </div>
            @endif
            @if ($counter)
                {{-- Менеджер ответил устно (28.10.2026) — за него: «Согласен» сразу отдаёт ему ТС, как его собственное «Согласен». --}}
                <div class="menu-group">
                    <form method="post" action="/confirmations/{{ $n }}/counter/answer" data-turbo-confirm="{{ \App\Offers\Warnings::TITLE }}" data-turbo-confirm-text="За менеджера: согласен на {{ Money::rub($bid->counter_amount) }}. ТС сразу уйдёт ему в сделку@if ($give), сделка {{ $offer->deal->buyer?->shortName() }} отменится @endif" data-turbo-confirm-label="Да, согласен" data-turbo-confirm-cancel="{{ \App\Offers\Warnings::CANCEL }}">@csrf<input type="hidden" name="agree" value="1"><button type="submit" class="menu-item w-full"><x-ui.icon name="check" class="size-5 text-ink-muted"/>За менеджера: согласен…</button></form>
                    <form method="post" action="/confirmations/{{ $n }}/counter/answer" data-turbo-confirm="{{ \App\Offers\Warnings::TITLE }}" data-turbo-confirm-text="За менеджера: отказался от {{ Money::rub($bid->counter_amount) }}. Его подтверждение снимется" data-turbo-confirm-label="Да, отказался" data-turbo-confirm-cancel="{{ \App\Offers\Warnings::CANCEL }}" data-turbo-confirm-danger>@csrf<input type="hidden" name="agree" value="0"><button type="submit" class="menu-item w-full"><x-ui.icon name="x" class="size-5 text-ink-muted"/>За менеджера: отказался…</button></form>
                    <form method="post" action="/confirmations/{{ $n }}/counter/withdraw" data-turbo-confirm="Отозвать контрпредложение?" data-turbo-confirm-label="Отозвать">@csrf<button type="submit" class="menu-item w-full"><x-ui.icon name="undo" class="size-5 text-ink-muted"/>Отозвать контрпредложение…</button></form>
                </div>
            @endif
            @if ($active)
                <div class="menu-group">
                    <form method="post" action="/confirmations/{{ $n }}/decline" data-turbo-confirm="Отклонить подтверждение {{ $who->shortName() }}?" data-turbo-confirm-label="Отклонить" data-turbo-confirm-danger>@csrf<button type="submit" class="menu-item w-full text-danger"><x-ui.icon name="x" class="size-5"/>Отклонить…</button></form>
                </div>
            @endif
        </x-ui.more>
    @else
        <div class="bid-line-main">{!! $main !!}</div>
    @endif
    @if ($active)<x-offer.give-sheet :offer="$offer" :bid="$bid"/>@endif
    {{-- Шторки пунктов — вне шторки строки (поповер прячет вложенное), открываются событием. --}}
    @if ($fix)<x-offer.bid-revise :bid="$bid" :offer="$offer" :event="'bid-revise-'.$n" staff :in-garage="(int) ($parked[$bid->user_id] ?? 0)"/>@endif
    @if ($haggle)<x-offer.counter-sheet :offer="$offer" :bid="$bid" :event="'counter-'.$n"/>@endif
</div>
