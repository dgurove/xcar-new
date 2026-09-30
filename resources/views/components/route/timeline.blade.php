{{-- Путь сделки у менеджера по блокам — тем же видом, что путь в CRM и таймлайн дела ТС (.steps): пройдено галочкой с датой,
     текущий крупнее с «чей ход», впереди серым. Без номеров — знаменателя нет, путь обрывается на развилке. --}}
@props(['blocks', 'current' => null, 'steps' => null, 'waiting' => null])
@php $passed = true; @endphp
<div class="steps">
    @foreach ($blocks as $block)
        @php
            $isCurrent = $block->id === $current;
            $done = $passed && ! $isCurrent;
            if ($isCurrent) $passed = false;
            $step = $steps?->last(fn ($s) => $s['block'] === $block->name);
        @endphp
        <div class="step step--{{ $isCurrent ? 'current' : ($done ? 'done' : 'next') }}">
            <span class="step-dot">@if ($done)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title min-w-0 flex-1">{{ $block->name }}</span>
                    @if ($done && $step)<span class="nums shrink-0 text-sm text-ink-dim">{{ $step['at']->translatedFormat('j M') }}</span>@endif
                </div>
                @if ($isCurrent && $waiting)<p class="step-hint">{{ $waiting }}</p>@endif
            </div>
        </div>
    @endforeach
</div>
