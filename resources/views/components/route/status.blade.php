{{-- Где оффер на ветке: чей ход и часы. --}}
@props(['position', 'block' => true])
@php
    $stage = $position->stage;
    $tone = $position->isOverdue() ? 'danger' : $stage->waits_for->tone();
    $class = match($tone) { 'open' => 'bg-open-soft text-open', 'urgent' => 'bg-urgent-soft text-urgent', 'danger' => 'bg-danger-soft text-danger', 'closed' => 'bg-closed-soft text-closed', default => '' };
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-1.5']) }}>
    @if ($block)<span class="chip">{{ $stage->block?->name }}@if ($stage->block && $stage->block->name !== $stage->name) <span class="text-ink-muted">› {{ $stage->name }}</span>@endif</span>@endif
    <span class="chip {{ $class }}">
        {{ $position->isOverdue() ? 'Срок вышел' : $stage->waits_for->label() }}
        @if ($position->deadline_at)
            <span class="tabular-nums" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-"></span>
        @elseif ($stage->timerMode() === 'stopwatch')
            <span class="tabular-nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}"></span>
        @endif
    </span>
</span>
