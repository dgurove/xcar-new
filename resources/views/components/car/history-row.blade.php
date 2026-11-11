{{-- Строка «Истории» предложения, как история дела ТС: текст во всю ширину, под ним мелко одной строкой «когда кто»
     (владелец 07.10.2026: дата своей строкой и имя колонкой справа — строка ради одной даты и ужатый текст). Кто — аватаром
     с именем, без человека — аватар системы (`x-ui.system-avatar`). --}}
{{-- Правка машины в другом месте (`Cars\LaneEdit`) — той же строкой, с подписью где. Строка одна у предложения, лота закупки
     и ТС парковки (`x-car.history`, 11.11.2026); заметка парковки — на подложке. --}}
@props(['event'])
@php
    $lane = $event instanceof \App\Cars\LaneEdit;
    $note = ! $lane && $event instanceof \App\Park\VehicleEvent && $event->type === \App\Park\EventType::Note;
@endphp
<div>
    <div @class(['leading-snug', 'history-note whitespace-pre-line' => $note])>{{ $event->text() }}@if (! $lane && method_exists($event, 'migtorgLot') && ($lot = $event->migtorgLot())) <a href="https://www.migtorg.com/lots/{{ $lot }}" class="nums text-accent-text hover:underline" target="_blank" rel="noopener">лот {{ $lot }}</a>@endif</div>
    <div class="mt-0.5 flex items-center gap-2 text-xs text-ink-dim">
        <span class="nums">{{ \App\Support\Ago::html($event->created_at, true, words: false) }}</span>
        {{-- Кто — аватаром и именем; без человека — система (26.10.2026): почта конвертом, остальное искрой. --}}
        {{-- Поставщик (partner.xcar.ru) — с вендором: «Ирина Соколова, АльфаСтрахование». --}}
        @if ($event->user)<span class="inline-flex items-center gap-1"><x-ui.avatar :user="$event->user" :size="16"/>{{ $event->user->shortName() }}@if ($event->user->isSupplier() && $event->user->vendor), {{ $event->user->vendor->name }}@endif</span>
        @else
            @php
                $p = is_array($event->payload ?? null) ? $event->payload : [];
                $via = $p['source'] ?? collect(['mail', 'migtorg', 'park'])->first(fn ($k) => isset($p[$k]));
                $via = $via === 'letter' ? 'mail' : $via;
            @endphp
            <span class="inline-flex items-center gap-1"><x-ui.system-avatar :via="$via" :size="16"/>{{ match ($via) { 'mail' => 'Почта', 'migtorg' => 'Мигторг', 'park' => 'Парковка', default => 'Автоматически' } }}</span>
        @endif
        @if ($lane)<span>{{ $event->where }}</span>@endif
    </div>
</div>
