{{-- Подтверждение менеджера — одно на редактор и карточка строки: сумма, насколько она от цены продажи, «Лучшая» и «ниже
     минимальной» (строкой группы .list в bids.blade), менеджер с телефоном, когда, комментарий. У ждущего — «Принять» (у предложения в сделке — «Отдать»:
     прежняя сделка отменится) листом «Отдать» с этим подтверждением (`x-offer.give-sheet`) и «Отклонить» рядом — одинаково в
     редакторе и карточке строки. Гаражное («В гараж») — без цены: сколько машин у менеджера уже в гараже. --}}
@props(['bid', 'offer', 'best' => false, 'parked' => collect()])
@php
    use App\Offers\BidState;
    use App\Support\Money;
    $active = $bid->state === BidState::Active;
    $garage = $bid->isGarage();
    $diff = $offer->asking_price && ! $garage ? $bid->amount - $offer->asking_price : null;
    $low = $active && ! $garage && ($min = $offer->minBid()) && $bid->amount < $min;
    // Не выше закупочной (14.10.2026, 3308 приняли в минус молча): красным в строке, «Принять» переспрашивает, и сервер
    // без согласия такую не примет (`Give`, `loss`).
    // Закупочная, с которой менеджер подтверждал (`bids.cost`, 15.10.2026), — она закрепится в сделке.
    $floor = $bid->frozenCost();
    $under = ! $garage && $floor && $bid->amount !== null ? $floor - $bid->amount : null;
    $loss = $under !== null && $under >= 0;
    $give = (bool) $offer->deal;
    $inGarage = $garage ? (int) ($parked[$bid->user_id] ?? 0) : 0;
@endphp
<div class="px-4 py-3">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="nums whitespace-nowrap text-lg font-semibold">{{ $garage ? 'В гараж' : Money::rub($bid->amount) }}</span>
        @if ($inGarage)<span class="nums text-sm text-ink-dim">в гараже {{ $inGarage }}</span>@endif
        @if ($diff)<span class="nums whitespace-nowrap text-sm text-ink-dim">{{ $diff > 0 ? '+' : '−' }}{{ Money::nums(abs($diff)) }} от цены</span>@endif
        @if ($best)<x-ui.state tone="soft">Лучшая</x-ui.state>@endif
        @if ($loss && $active)<x-ui.state tone="danger">{{ $under > 0 ? 'ниже закупочной на '.Money::nums($under) : 'равна закупочной' }}</x-ui.state>@elseif ($low)<x-ui.state tone="urgent">ниже минимальной</x-ui.state>@endif
        @if (! $active)<x-ui.state :tone="$bid->state === BidState::Accepted ? 'open' : ($bid->state === BidState::Declined ? 'danger' : 'closed')">{{ $bid->state->label() }}</x-ui.state>@endif
    </div>
    {{-- Кто — человеком, телефон ссылкой и когда — текстом (10.10.2026: «что-то чипами, что-то текстом»). --}}
    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$bid->user" full/>@if ($bid->user->isSuperBuyer())<span class="text-ink-dim">супер-покупатель</span>@endif @if ($bid->user->phone)<x-ui.phone :user="$bid->user"/>@endif<span class="nums text-ink-dim">{{ $bid->created_at->translatedFormat('j M, H:i') }}</span>@if ($bid->byStaff())<span class="text-ink-dim">внёс {{ $bid->placedBy?->shortName() }}</span>@endif</div>
    @if ($bid->comment)<div class="mt-1 text-sm">{{ $bid->comment }}</div>@endif
    @if ($active)
        <div class="mt-2 flex items-start gap-2">
            <x-offer.give-sheet :offer="$offer" :bid="$bid">
                <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $give ? 'Отдать' : 'Принять' }}</x-ui.button>
            </x-offer.give-sheet>
            <form method="post" action="/confirmations/{{ $bid->id }}/decline" data-turbo-confirm="Отклонить подтверждение {{ $bid->user->shortName() }}?">@csrf<x-ui.button size="sm" variant="ghost">Отклонить</x-ui.button></form>
        </div>
    @endif
</div>
