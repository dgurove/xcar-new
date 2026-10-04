{{-- Строка «Истории» предложения, как история дела ТС: сверху мелко дата, ниже текст во всю ширину, справа кто — в
     узкой колонке строка не ломается лесенкой. --}}
<div>
    <div class="nums text-xs text-ink-dim">{{ $event->created_at->translatedFormat('j M, H:i') }}</div>
    <div class="mt-0.5 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1 leading-snug">{{ $event->text() }}@if ($lot = $event->migtorgLot()) <a href="https://www.migtorg.com/lots/{{ $lot }}" class="nums text-accent-text hover:underline" target="_blank" rel="noopener">лот {{ $lot }}</a>@endif</div>
        @if ($event->user)<span class="shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>
        @elseif (($event->payload['source'] ?? null) === 'migtorg')<span class="shrink-0 pt-1" title="Мигторг"><x-offer.migtorg-mark/></span>@endif
    </div>
</div>
