{{-- Своё подтверждение, когда подтверждать уже нельзя: цена (или «В гараж») и что с ним. Принятое ведёт в сделку,
     гаражное — в гараж. --}}
@props(['offer', 'bid'])
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
    <span class="text-sm {{ $deal ? 'text-accent-text' : 'text-ink-muted' }}">{{ $deal ? ($deal->isGarage() ? 'В гараже' : 'Сделка') : 'Ждёт решения' }}</span>
@if ($deal)</a>@else</div>@endif
