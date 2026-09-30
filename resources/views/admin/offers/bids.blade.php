{{-- Подтверждения предложения — одно на редактор и окошко строки (inline). Без сделки — ждущие по сумме вниз, лучшая
     отмечена. Со сделкой — принятое сверху со ссылкой в сделку, ниже «Резерв N»: остальные ждущие не отклоняются,
     выбранный передумал — «Отдать» другому. Отклонённые и отозванные — свёрнуто. --}}
@php
    use App\Offers\BidState;
    $inline ??= false;
    $deal = $offer->deal;
    $active = $offer->bids->where('state', BidState::Active)->sortByDesc('amount')->values();
    $accepted = $deal ? $offer->bids->firstWhere('id', $deal->bid_id) : null;
    $rest = $offer->bids->reject(fn ($b) => $b->state === BidState::Active || $b->is($accepted))->sortByDesc('created_at')->values();
@endphp
<div class="flex flex-col gap-2">
    @if ($accepted)
        <x-offer.bid :bid="$accepted" :offer="$offer" :inline="$inline"/>
        <a href="/work/deals/{{ $deal->id }}" class="btn btn-s btn-quiet self-start" data-turbo-frame="_top">Открыть сделку</a>
        @if ($active->isNotEmpty())<div class="mt-2 text-sm font-medium text-ink-muted">Резерв <span class="nums">{{ $active->count() }}</span></div>@endif
    @endif
    @foreach ($active as $bid)
        <x-offer.bid :bid="$bid" :offer="$offer" :best="! $deal && $loop->first && $active->count() > 1" :inline="$inline"/>
    @endforeach
    @if ($rest->isNotEmpty())
        <details class="mt-1">
            <summary class="letter-link cursor-pointer">Ещё {{ $rest->count() }}</summary>
            <div class="mt-2 flex flex-col gap-2">@foreach ($rest as $bid)<x-offer.bid :bid="$bid" :offer="$offer" :inline="$inline"/>@endforeach</div>
        </details>
    @endif
</div>
