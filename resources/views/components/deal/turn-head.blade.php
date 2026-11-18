{{-- Шапка шага сделки у менеджера одной строкой (18.11.2026, владелец): слева значок — наш ход серыми часами, его — «!» в жёлтом
     круге того же размера; заголовок слева по середине; справа по середине время — у его хода сколько осталось (вышло — «−3ч»
     красным), у нашего — сколько идёт. Описаний под ней нет. Чей ход и слова — `Offers\Turn`. --}}
@props(['turn'])
<div {{ $attributes->class('flex items-center gap-3') }}>
    @if ($turn->mine)
        <svg class="size-6 shrink-0" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" style="fill: var(--color-yellow)"/><path d="M12 7v6.5" stroke="#1c1c1e" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="17" r="1.35" fill="#1c1c1e"/></svg>
    @else
        <x-ui.icon name="clock" class="size-6 shrink-0 text-ink-dim"/>
    @endif
    <h2 class="min-w-0 flex-1">{{ $turn->title }}</h2>
    @if ($turn->mine && $turn->until)
        <span @class(['nums shrink-0 whitespace-nowrap text-ink-muted', '!text-danger' => $turn->until->isPast()]) data-controller="timer" data-timer-until-value="{{ $turn->until->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ $turn->until->isPast() ? '−'.\App\Support\Ago::since($turn->until) : \App\Support\Ago::left($turn->until, '') }}</span>
    @elseif (! $turn->mine && $turn->since)
        <span class="nums shrink-0 whitespace-nowrap text-ink-muted" data-controller="timer" data-timer-since-value="{{ $turn->since->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($turn->since) }}</span>
    @endif
</div>
