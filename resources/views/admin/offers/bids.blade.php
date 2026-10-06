{{-- Подтверждения предложения — одно на редактор и карточка строки. Без сделки — ждущие по сумме вниз, лучшая
     отмечена. Со сделкой — принятое сверху со ссылкой в сделку, ниже «Резерв N»: остальные ждущие не отклоняются,
     выбранный передумал — «Отдать» другому. Отклонённые и отозванные — свёрнуто. Внизу — «+ За менеджера» (админ вносит
     подтверждение сам, `x-offer.bid-for`), у предложения в продаже и в сделке. --}}
@php
    use App\Offers\BidState;
    // Идущая сделка, а в редакторе (`inside`) — и состоявшаяся: её принятое подтверждение сверху.
    $deal = $offer->deal ?? (($inside ?? false) ? ($deal ?? null) : null);
    // Гаражные (без цены) — после ценовых; «Лучшая» — среди цен.
    $active = $offer->bids->where('state', BidState::Active)->sortByDesc('amount')->values();
    $priced = $active->reject->isGarage();
    // Для гаражных — одним запросом на список: сколько машин у каждого уже в гараже и есть ли у маршрута ветка «платим мы».
    $garageBids = $offer->bids->filter->isGarage();
    $parked = $garageBids->isEmpty() ? collect() : \App\Garage\Car::whereIn('manager_id', $garageBids->pluck('user_id'))
        ->whereNotIn('state', [\App\Garage\CarState::Sold, \App\Garage\CarState::Settled])->selectRaw('manager_id, count(*) as n')->groupBy('manager_id')->pluck('n', 'manager_id');
    $branch = $garageBids->isNotEmpty() && $offer->garageBranch();
    $best = $priced->count() > 1 ? $priced->first() : null;
    $accepted = $deal ? $offer->bids->firstWhere('id', $deal->bid_id) : null;
    $rest = $offer->bids->reject(fn ($b) => $b->state === BidState::Active || $b->is($accepted))->sortByDesc('created_at')->values();
@endphp
{{-- Группы строк через линию (.list), а не плашка на каждое подтверждение. --}}
<div class="flex flex-col gap-2">
    @if ($accepted)
        <div class="list"><x-offer.bid :bid="$accepted" :offer="$offer" :parked="$parked" :branch="$branch"/></div>
        @unless ($inside ?? false)<a href="/offers/{{ $offer->number }}" class="btn btn-s btn-quiet self-start" data-turbo-frame="_top">Открыть сделку</a>@endunless
        @if ($active->isNotEmpty())<div class="mt-2 px-1 text-sm text-ink-dim">Резерв <span class="nums">{{ $active->count() }}</span></div>@endif
    @endif
    @if ($active->isNotEmpty())
        <div class="list">
            @foreach ($active as $bid)
                <x-offer.bid :bid="$bid" :offer="$offer" :best="! $deal && $best && $bid->is($best)" :parked="$parked" :branch="$branch"/>
            @endforeach
        </div>
    @endif
    @if ($rest->isNotEmpty())
        <details class="mt-1">
            <summary class="letter-link cursor-pointer">Ещё {{ $rest->count() }}</summary>
            <div class="list mt-2">@foreach ($rest as $bid)<x-offer.bid :bid="$bid" :offer="$offer" :parked="$parked" :branch="$branch"/>@endforeach</div>
        </details>
    @endif
    @if (auth()->user()?->canManageCrm() && in_array($offer->state, [\App\Offers\OfferState::Open, \App\Offers\OfferState::Sold], true))
        <x-offer.bid-for :offer="$offer"/>
    @endif
</div>
