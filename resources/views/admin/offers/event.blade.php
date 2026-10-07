{{-- Строка «Истории» предложения, как история дела ТС: текст во всю ширину, под ним мелко одной строкой «когда кто»
     (владелец 07.10.2026: дата своей строкой и имя колонкой справа — строка ради одной даты и ужатый текст). Мигторг —
     его знаком на месте имени. --}}
<div>
    <div class="leading-snug">{{ $event->text() }}@if ($lot = $event->migtorgLot()) <a href="https://www.migtorg.com/lots/{{ $lot }}" class="nums text-accent-text hover:underline" target="_blank" rel="noopener">лот {{ $lot }}</a>@endif</div>
    <div class="mt-0.5 flex items-center gap-2 text-xs text-ink-dim">
        <span class="nums">{{ $event->created_at->translatedFormat('j M, H:i') }}</span>
        @if ($event->user)<span>{{ $event->user->shortName() }}</span>
        @elseif (($event->payload['source'] ?? null) === 'migtorg')<span title="Мигторг"><x-offer.migtorg-mark/></span>@endif
    </div>
</div>
