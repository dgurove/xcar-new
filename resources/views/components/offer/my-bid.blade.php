{{-- Своё подтверждение, когда подтверждать уже нельзя: цена (или «В гараж») и что с ним. Принятое ведёт в сделку,
     гаражное — в гараж. --}}
@props(['offer', 'bid'])
<x-offer.counter-card :bid="$bid" class="mb-3 w-full"/>
@php $deal = $bid->state === \App\Offers\BidState::Accepted ? $offer->deal()->where('bid_id', $bid->id)->first() : null; @endphp
@if ($deal)
    <a href="{{ $deal->href() }}" {{ $attributes->class('flex items-center gap-3') }}>
@else
    <div {{ $attributes->class('flex items-center gap-3') }}>
@endif
    <span class="min-w-0 flex-1">
        <span class="block text-sm text-ink-muted">Ваше подтверждение</span>
        <span class="nums block font-semibold">{{ $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount) }}</span>
    </span>
    @php $lowMine = ! $deal && ! $bid->isGarage() && $bid->amount && $offer->bidZone($bid->amount) === 'low'; @endphp
    <span class="text-right text-sm {{ $deal ? 'text-accent-text' : 'text-ink-muted' }}">{{ $deal ? ($deal->isGarage() ? 'В гараже' : 'Сделка') : 'Ждёт решения' }}@if ($lowMine)<span class="block text-warn">Нужно согласование с поставщиком</span>@endif</span>
@if ($deal)</a>@else</div>@endif
