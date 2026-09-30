{{-- Подтверждения предложения — одно на редактор и окошко строки. Без сделки — ждущие по сумме вниз, лучшая
     отмечена. Со сделкой — принятое сверху со ссылкой в сделку, ниже «Резерв N»: остальные ждущие не отклоняются,
     выбранный передумал — «Отдать» другому. Отклонённые и отозванные — свёрнуто. --}}
@php
    use App\Offers\BidState;
    $deal = $offer->deal;
    $active = $offer->bids->where('state', BidState::Active)->sortByDesc('amount')->values();
    $accepted = $deal ? $offer->bids->firstWhere('id', $deal->bid_id) : null;
    $rest = $offer->bids->reject(fn ($b) => $b->state === BidState::Active || $b->is($accepted))->sortByDesc('created_at')->values();
@endphp
{{-- Группы строк через линию (.list), а не плашка на каждое подтверждение. --}}
<div class="flex flex-col gap-2">
    @if ($accepted)
        <div class="list"><x-offer.bid :bid="$accepted" :offer="$offer"/></div>
        <a href="/work/deals/{{ $deal->id }}" class="btn btn-s btn-quiet self-start" data-turbo-frame="_top">Открыть сделку</a>
        @if ($active->isNotEmpty())<div class="mt-2 px-1 text-sm text-ink-dim">Резерв <span class="nums">{{ $active->count() }}</span></div>@endif
    @endif
    @if ($active->isNotEmpty())
        <div class="list">
            @foreach ($active as $bid)
                <x-offer.bid :bid="$bid" :offer="$offer" :best="! $deal && $loop->first && $active->count() > 1"/>
            @endforeach
        </div>
    @endif
    @if ($rest->isNotEmpty())
        <details class="mt-1">
            <summary class="letter-link cursor-pointer">Ещё {{ $rest->count() }}</summary>
            <div class="list mt-2">@foreach ($rest as $bid)<x-offer.bid :bid="$bid" :offer="$offer"/>@endforeach</div>
        </details>
    @endif
</div>
