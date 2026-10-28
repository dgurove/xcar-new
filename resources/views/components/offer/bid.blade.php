{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, «Лучшая», светофор
     строки (не ниже порога — сумма зелёным, ниже — жёлтая заливка, 26.10.2026; строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) листом «Отдать» с этим подтверждением (`x-offer.give-sheet`) и «Отклонить» рядом — одинаково в
     редакторе и карточке строки. Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже. --}}
@props(['bid', 'offer', 'best' => false, 'parked' => collect()])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $garage = $bid->isGarage();
    $diff = $offer->asking_price && ! $garage ? $bid->amount - $offer->asking_price : null;
    // Светофор строки (26.10.2026): ниже порога — жёлтым, без слов (`Offer::bidZone`).
    $low = $active && ! $garage && $bid->amount && $offer->bidZone($bid->amount) !== 'ok';
    // Не выше закупочной (14.10.2026, 3308 приняли в минус молча): красным в строке, «Принять» переспрашивает, и сервер
    // без согласия такую не примет (`Give`, `loss`).
    // Закупочная, с которой менеджер подтверждал (`bids.cost`, 15.10.2026), — она закрепится в сделке.
    $floor = $bid->frozenCost();
    $under = ! $garage && $floor && $bid->amount !== null ? $floor - $bid->amount : null;
    $loss = $under !== null && $under >= 0;
    $give = (bool) $offer->deal;
    $inGarage = $garage ? (int) ($parked[$bid->user_id] ?? 0) : 0;
@endphp
<div @class(['px-4 py-3', 'bid-row--loss' => $loss && $active, 'bid-row--low' => $low && ! ($loss && $active), 'bid-row--ok' => $active && ! $garage && ! $low && ! $loss])>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="bid-amount nums whitespace-nowrap text-lg font-semibold">{{ $garage ? 'В гараж' : Money::rub($bid->amount) }}</span>
        @if ($inGarage)<span class="nums text-sm text-ink-dim">в гараже {{ $inGarage }}</span>@endif
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($best)<x-ui.state tone="soft">Лучшая</x-ui.state>@endif
        @if ($loss && $active)<x-ui.state tone="danger">{{ $under > 0 ? 'ниже закупочной на '.Money::nums($under) : 'равна закупочной' }}</x-ui.state>@endif
        @if (! $active)<x-ui.state :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')">{{ $bid->state->label() }}</x-ui.state>@endif
    </div>
    {{-- Кто — человеком, телефон ссылкой и когда — текстом (10.10.2026: «что-то чипами, что-то текстом»). --}}
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/>@if ($bid->user->isSuperBuyer())<span class="text-ink-dim">супер-покупатель</span>@endif @if ($bid->user->phone)<x-ui.phone :user="$bid->user"/>@endif<span class="nums text-ink-dim">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span>@if ($bid->byStaff())<span class="text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</span>@endif</div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    {{-- Контрпредложение (26.10.2026): ждём ответа — акцентной строкой с «Отозвать», отказался — серым. --}}
    @if ($bid->hasPendingCounter())
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <x-ui.state tone="accent"><span class="nums">→ {{ Money::rub($bid->counter_amount) }}</span>, ждём ответа</x-ui.state>
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/withdraw" class="contents" data-turbo-confirm="Отозвать контрпредложение?" data-turbo-confirm-label="Отозвать">@csrf<button type="submit" class="letter-link">Отозвать</button></form>
            {{-- Менеджер ответил устно (28.10.2026) — за него: «Согласен» сразу отдаёт ему ТС, как его собственное «Согласен». --}}
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/answer" class="contents" data-turbo-confirm="За менеджера: согласен на {{ Money::rub($bid->counter_amount) }}?" data-turbo-confirm-text="ТС сразу уйдёт ему в сделку" data-turbo-confirm-label="Согласен">@csrf<input type="hidden" name="agree" value="1"><button type="submit" class="letter-link">Согласен за менеджера</button></form>
            <form method="post" action="/confirmations/{{ $bid->id }}/counter/answer" class="contents" data-turbo-confirm="За менеджера: отказался от {{ Money::rub($bid->counter_amount) }}?" data-turbo-confirm-label="Отказался">@csrf<input type="hidden" name="agree" value="0"><button type="submit" class="letter-link">Отказался за менеджера</button></form>
        </div>
    @elseif ($bid->counterDeclined())
        <div class="mt-1 text-sm text-ink-dim">отказался от <span class="nums">{{ Money::rub($bid->counter_amount) }}</span></div>
    @endif
    @if ($active)
        <div class="mt-2 flex flex-wrap items-start gap-2">
            <x-offer.give-sheet :offer="$offer" :bid="$bid">
                <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? 'Отдать' : 'Принять' }}</x-ui.button>
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
