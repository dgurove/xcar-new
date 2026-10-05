{{-- Шапка экрана денег, как в банковском приложении: фото ТС кружком, подпись, число крупно (главный показатель
     экрана — правило 6) и под ним словом, что с ним сейчас, цветом состояния. --}}
@props(['offer' => null, 'caption' => null, 'amount', 'phrase' => null, 'tone' => null])
@php
    $cls = match ($tone) { 'urgent' => 'text-urgent', 'danger' => 'text-danger', 'accent' => 'text-accent-text', 'profit' => 'text-open', default => 'text-ink-muted' };
@endphp
<div {{ $attributes->class('money-hero') }}>
    @if ($offer)<span class="money-hero-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></span>@endif
    @if ($caption)<span class="text-sm text-ink-muted">{{ $caption }}</span>@endif
    <span class="nums text-[32px] font-semibold leading-tight">{{ \App\Support\Money::rub($amount) }}</span>
    @if ($phrase)<span class="{{ $cls }}">{{ $phrase }}</span>@endif
</div>
