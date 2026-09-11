{{-- Путь сделки по блокам: пройдено, текущий (лаймом), впереди. Без номеров —
     знаменателя нет, путь обрывается на развилке. --}}
@props(['blocks', 'current' => null, 'steps' => null, 'waiting' => null])
@php $passed = true; @endphp
<ol class="space-y-4">
    @foreach ($blocks as $block)
        @php
            $isCurrent = $block->id === $current;
            $done = $passed && !$isCurrent;
            if ($isCurrent) $passed = false;
            $step = $steps?->last(fn ($s) => $s['block'] === $block->name);
        @endphp
        <li class="flex items-start gap-3">
            <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $isCurrent ? 'bg-accent' : ($done ? 'bg-ink-dim' : 'bg-surface-3') }}"></span>
            <span class="min-w-0">
                <span class="block {{ $isCurrent ? 'font-medium' : ($done ? 'text-ink-muted' : 'text-ink-dim') }}">{{ $block->name }}</span>
                @if ($isCurrent && $waiting)<span class="block text-sm text-ink-dim">{{ $waiting }}</span>
                @elseif ($step && $done)<span class="nums block text-sm font-normal text-ink-dim">{{ $step['at']->translatedFormat('j M, H:i') }}</span>@endif
            </span>
        </li>
    @endforeach
</ol>
