{{-- Где оффер на ветке: блок › этап пилюлей и чей ход с часами. --}}
@props(['position', 'block' => true])
@php
    $stage = $position->stage;
    $tone = $position->isOverdue() ? 'danger' : ($stage->waits_for->tone() === 'plain' ? 'plain' : $stage->waits_for->tone());
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-1.5']) }}>
    @if ($block)<x-ui.pill tone="plain" class="shrink whitespace-normal text-left">{{ $stage->block?->name }}@if ($stage->block && $stage->block->name !== $stage->name) <span class="text-ink-dim">› {{ $stage->name }}</span>@endif</x-ui.pill>@endif
    <x-ui.pill :tone="$tone">
        {{ $position->isOverdue() ? 'Срок вышел' : $stage->waits_for->label() }}
        @if ($position->deadline_at)
            <span class="nums" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-"></span>
        @elseif ($stage->timerMode() === 'stopwatch')
            <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}"></span>
        @endif
    </x-ui.pill>
</span>
