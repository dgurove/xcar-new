{{-- Строка «Истории» предложения, как история дела ТС: сверху мелко дата, ниже текст во всю ширину, справа кто — в
     узкой колонке строка не ломается лесенкой. --}}
<div>
    <div class="nums text-xs text-ink-dim">{{ $event->created_at->translatedFormat('j M, H:i') }}</div>
    <div class="mt-0.5 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1 leading-snug">{{ $event->text() }}</div>
        @if ($event->user)<span class="shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
    </div>
</div>
