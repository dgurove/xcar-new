@props(['amount', 'muted' => false])
@if ($amount)
    <span {{ $attributes->merge(['class' => 'font-semibold tabular-nums '.($muted ? 'text-ink-muted' : '')]) }}>{{ number_format($amount, 0, '', ' ') }} ₽</span>
@endif
