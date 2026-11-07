{{-- Своё подтверждение, когда подтверждать уже нельзя: цена (или «В гараж») и что с ним. Принятое ведёт в сделку,
     гаражное — в гараж. --}}
@props(['offer', 'bid'])
<x-offer.counter-card :bid="$bid" class="mb-3 w-full"/>
<x-offer.reprice-ask :bid="$bid" class="mb-3 w-full"/>
@php $deal = $bid->state === \App\Offers\BidState::Accepted ? \App\Offers\Deal::where('bid_id', $bid->id)->whereIn('state', [\App\Offers\DealState::Active, \App\Offers\DealState::Parallel])->latest('id')->first() : null; @endphp
@if ($deal)
    <a href="{{ $deal->href() }}" {{ $attributes->class('flex items-center gap-3') }}>
@else
    <div {{ $attributes->class('flex items-center gap-3') }}>
@endif
    <span class="min-w-0 flex-1">
        <span class="block text-sm text-ink-muted">Ваше подтверждение</span>
        <span class="nums block font-semibold">{{ $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount) }}</span>
    </span>
    @php
        $zoneMine = ! $deal && ! $bid->isGarage() && $bid->amount ? $offer->bidZone($bid->amount) : 'ok';
        $lowMine = $zoneMine === 'low';
    @endphp
    <span class="text-right text-sm {{ $deal ? 'text-accent-text' : 'text-ink-muted' }}">{{ $deal ? ($deal->isGarage() ? 'В гараже' : 'Сделка') : 'Ждёт решения' }}@if ($lowMine)<span class="block text-warn">Нужно согласование с поставщиком</span>@elseif ($zoneMine === 'high')<span class="block text-danger">Проверьте сумму: больше чем вдвое выше цены</span>@endif</span>
@if ($deal)</a>@else</div>@endif
