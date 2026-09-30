{{-- Своё подтверждение, когда подтверждать уже нельзя: цена и что с ним. Принятое ведёт в сделку. --}}
@props(['offer', 'bid'])
@php $deal = $bid->state === \App\Offers\BidState::Accepted ? $offer->deal()->where('bid_id', $bid->id)->first() : null; @endphp
@if ($deal)
    <a href="/deals/{{ $deal->id }}" {{ $attributes->class('row bg-surface-2') }}>
@else
    <div {{ $attributes->class('row bg-surface-2') }}>
@endif
    <span class="min-w-0 flex-1">
        <span class="block text-sm text-ink-muted">Ваше подтверждение</span>
        <span class="nums block font-semibold">{{ \App\Support\Money::rub($bid->amount) }}</span>
    </span>
    <span class="text-sm {{ $deal ? 'text-accent-text' : 'text-ink-muted' }}">{{ $deal ? 'Сделка' : 'Ждёт решения' }}</span>
@if ($deal)</a>@else</div>@endif
