{{-- Подтверждения предложения — одно на редактор и карточка строки. Ждущие — по сумме вниз, при равной сумме выше тот,
     кто подтвердил раньше (01.11.2026, начальник: «кто первый подтвердил, тот и победил»); «Лучшей» нет, когда подтвердил —
     справа в строке суммы (владелец: так и порядок виден). Со сделкой — принятое сверху со ссылкой в сделку («Перейти к сделке»: «Открыть сделку» читали как
     действие, которое ещё не сделано, 18.10.2026), ниже «Резерв N»: остальные ждущие не отклоняются,
     выбранный передумал — «Отдать» другому. Отклонённые и отозванные — свёрнуто. Внизу — «+ За менеджера» (админ вносит
     подтверждение сам, `x-offer.bid-for`), у предложения в продаже и в сделке. --}}
@php
    use App\Offers\BidState;
    // Идущая сделка, а в редакторе (`inside`) — и состоявшаяся: её принятое подтверждение сверху.
    $deal = $offer->deal ?? (($inside ?? false) ? ($deal ?? null) : null);
    // Гаражные (без цены) — после ценовых.
    $active = $offer->bids->where('state', BidState::Active)
        ->sortBy([fn ($a, $b) => $a->isGarage() <=> $b->isGarage(), ['amount', 'desc'], ['created_at', 'asc'], ['id', 'asc']])->values();
    // Для гаражных — одним запросом на список: сколько машин у каждого уже в гараже.
    $garageBids = $offer->bids->filter->isGarage();
    $parked = $garageBids->isEmpty() ? collect() : \App\Garage\Car::whereIn('manager_id', $garageBids->pluck('user_id'))
        ->whereNotIn('state', [\App\Garage\CarState::Sold, \App\Garage\CarState::Settled])->selectRaw('manager_id, count(*) as n')->groupBy('manager_id')->pluck('n', 'manager_id');
    $accepted = $deal ? $offer->bids->firstWhere('id', $deal->bid_id) : null;
    // Подтверждения параллельных сделок — их блоком «Параллельно» (07.11.2026), не в «Ещё».
    $parallelBids = \App\Offers\Deal::where('offer_id', $offer->id)->where('state', \App\Offers\DealState::Parallel)->pluck('bid_id')->filter();
    // Заменённое новой ценой — не отдельное подтверждение: смена цены видна в «Истории».
    $rest = $offer->bids->reject(fn ($b) => in_array($b->state, [BidState::Active, BidState::Replaced], true) || $b->is($accepted) || $parallelBids->contains($b->id))->sortByDesc('created_at')->values();
@endphp
{{-- Группы строк через линию (.list), а не плашка на каждое подтверждение. --}}
<div class="flex flex-col gap-2">
    @if ($accepted)
        <div class="list"><x-offer.bid :bid="$accepted" :offer="$offer" :parked="$parked" :deal="$offer->deal?->bid_id === $accepted->id ? $offer->deal : null"/></div>
        @unless ($inside ?? false)<a href="{{ $offer->crmPath() }}" class="btn btn-s btn-quiet self-start" data-turbo-frame="_top">Перейти к сделке</a>@endunless
    @endif
    @if ($parallelBids->isNotEmpty() && auth()->user()?->canManageCrm())
        @include('admin.offers.parallels', ['offer' => $offer, 'main' => $offer->deal])
    @endif
    @if ($accepted && $active->isNotEmpty())
        <div class="mt-2 px-1 text-sm text-ink-dim">Резерв <span class="nums">{{ $active->count() }}</span></div>
    @endif
    @if ($active->isNotEmpty())
        <div class="list">
            @foreach ($active as $bid)
                <x-offer.bid :bid="$bid" :offer="$offer" :parked="$parked"/>
            @endforeach
        </div>
    @endif
    @if ($rest->isNotEmpty())
        <details class="mt-1">
            <summary class="letter-link cursor-pointer">Ещё {{ $rest->count() }}</summary>
            <div class="list mt-2">@foreach ($rest as $bid)<x-offer.bid :bid="$bid" :offer="$offer" :parked="$parked"/>@endforeach</div>
        </details>
    @endif
    {{-- Та же машина в закупке (29.10.2026, владелец: «не видно в подтверждениях, сколько за неё предлагали в закупке»):
         последняя цена каждого менеджера и наша — группой после подтверждений продажи. --}}
    @if (auth()->user()?->canManageCrm() && $offer->purchaseCar?->hasPrices())
        <div @class(['px-1 text-sm text-ink-dim', 'mt-2' => $offer->bids->isNotEmpty()])>В закупке № {{ $offer->purchaseCar->purchase->number }}</div>
        @include('admin.offers.purchase-prices', ['car' => $offer->purchaseCar])
    @endif
    @if (auth()->user()?->canManageCrm() && in_array($offer->state, [\App\Offers\OfferState::Open, \App\Offers\OfferState::Sold], true))
        <x-offer.bid-for :offer="$offer"/>
    @endif
</div>
