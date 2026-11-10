{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, когда подтвердил — справа в строке суммы, светофор
     строки (не ниже порога — сумма зелёным, ниже — жёлтая заливка, 26.10.2026; строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) листом «Отдать» с этим подтверждением (`x-offer.give-sheet`) и «Отклонить» рядом — одинаково в
     редакторе и карточке строки. Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже. --}}
@props(['bid', 'offer', 'parked' => collect(), 'deal' => null])
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
<div @class(['px-4 py-3', 'bid-row--loss' => ($loss && $active) || $high, 'bid-row--low' => $low && ! ($loss && $active), 'bid-row--ok' => $active && ! $garage && ! $low && ! $loss && ! $high])>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="bid-amount nums whitespace-nowrap text-lg font-semibold">{{ $garage ? 'В гараж' : Money::rub($price) }}</span>
        {{-- Опечатка в цене (02.11.2026, «9 400 000 вместо 940 000») — карандаш у суммы: поправить, а не отклонять. --}}
        @if ($active && ! $garage && $bid->amount)
            @php $tenth = (int) round($bid->amount / 10, -3); @endphp
            <span class="contents" data-controller="sheet">
                <button type="button" class="btn btn-ghost btn-s -my-1 px-1.5" data-action="sheet#open" aria-label="Поправить цену" title="Поправить цену"><x-ui.icon name="edit" class="size-4"/></button>
                <x-ui.sheet :id="'bid-amount-'.$bid->id" title="Поправить цену">
                    <form method="post" action="/confirmations/{{ $bid->id }}/amount" class="flex flex-col gap-4" data-put-scope>
                        @csrf
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/><span class="text-ink-muted">подтвердил <span class="nums">{{ Money::rub($bid->amount) }}</span>@if ($offer->asking_price), цена продажи <span class="nums">{{ Money::rub($offer->asking_price) }}</span>@endif</span></div>
                        <label class="flex flex-col items-center gap-1">
                            <span class="flex items-baseline justify-center gap-2">
                                <input name="amount" inputmode="decimal" autocomplete="off" class="pay-amount nums" aria-label="Цена, ₽" placeholder="0"
                                       value="{{ old('amount', Money::nums($high && $tenth ? $tenth : $bid->amount)) }}" data-controller="digits" data-action="input->digits#format" autofocus>
                                <span class="text-xl text-ink-muted">₽</span>
                            </span>
                            @error('amount')<span class="text-sm text-danger">{{ $message }}</span>@enderror
                        </label>
                        @if ($tenth)
                            <div class="sheet-chips justify-center">
                                <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($tenth) }}" data-put-keep-param="true">Без лишнего нуля {{ Money::nums($tenth) }}</button>
                                <button type="button" class="chip nums" data-controller="put" data-action="put#set" data-put-value-param="{{ Money::nums($bid->amount) }}" data-put-keep-param="true">Как было {{ Money::nums($bid->amount) }}</button>
                            </div>
                        @endif
                        <x-ui.sheet-foot type="submit">Сохранить</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </span>
        @endif
        @if ($inGarage)<span class="nums text-sm text-ink-dim">в гараже {{ $inGarage }}</span>@endif
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($high)<x-ui.state tone="danger">больше чем вдвое выше цены — проверьте сумму</x-ui.state>@endif
        @if ($loss && $active)<x-ui.state tone="danger">{{ $under > 0 ? 'ниже закупочной на '.Money::nums($under) : 'равна закупочной' }}</x-ui.state>@endif
        @if (! $active)<x-ui.state :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')">{{ $bid->state->label() }}</x-ui.state>@endif
        <span class="nums ml-auto whitespace-nowrap text-sm text-ink-dim">{{ \App\Support\Ago::html($bid->created_at, true, words: false) }}</span>
    </div>
    {{-- Кто — человеком, телефон ссылкой и когда — текстом (10.10.2026: «что-то чипами, что-то текстом»). --}}
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/>@if ($bid->user->isSuperBuyer())<span class="text-ink-dim">супер-покупатель</span>@endif @if ($bid->user->phone)<x-ui.phone :user="$bid->user"/>@endif @if ($bid->byStaff())<span class="text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</span>@endif</div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    {{-- Контрпредложение (26.10.2026): ждём ответа — акцентной строкой с «Отозвать», отказался — серым. --}}
    @if ($bid->hasPendingCounter())
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <x-ui.state tone="accent"><span class="nums">→ {{ Money::rub($bid->counter_amount) }}</span>, ждём ответа</x-ui.state>
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/withdraw" class="contents" data-turbo-confirm="Отозвать контрпредложение?" data-turbo-confirm-label="Отозвать">@csrf<button type="submit" class="letter-link">Отозвать</button></form>
            {{-- Менеджер ответил устно (28.10.2026) — за него: «Согласен» сразу отдаёт ему ТС, как его собственное «Согласен». --}}
            <x-offer.answer-for :action="'/confirmations/'.$bid->id.'/counter/answer'" :agree-ask="'За менеджера: согласен на '.Money::rub($bid->counter_amount).'?'"
                agree-text="ТС сразу уйдёт ему в сделку" :decline-ask="'За менеджера: отказался от '.Money::rub($bid->counter_amount).'?'"/>
        </div>
    @elseif ($bid->counterDeclined())
        <div class="mt-1 text-sm text-ink-dim">отказался от <span class="nums">{{ Money::rub($bid->counter_amount) }}</span></div>
    @endif
    @if ($active)
        <div class="mt-2 flex flex-wrap items-start gap-2">
            <x-offer.give-sheet :offer="$offer" :bid="$bid">
                <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? ($twoWays ? 'Отдать…' : 'Отдать') : 'Принять' }}</x-ui.button>
            </x-offer.give-sheet>
            @if (! $garage && ! $give)
                <x-offer.counter-sheet :offer="$offer" :bid="$bid">
                    <x-ui.button type="button" size="sm" variant="ghost" data-action="sheet#open">{{ $bid->hasPendingCounter() ? 'Другая цена' : 'Контрпредложение' }}</x-ui.button>
                </x-offer.counter-sheet>
            @endif
            <form method="post" action="/confirmations/{{ $bid->id }}/decline" data-turbo-confirm="Отклонить подтверждение {{ $bid->user->shortName() }}?">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
        </div>
    @endif
</div>
