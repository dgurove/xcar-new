{{-- «Цепочки», «Входящие» и «Отправленные» — письма строками (x-mail.inbox-row); строка открывает письмо справа (`Detail`).
     Три раскладки (22.11.2026, владелец; вид пачки — вариант А из макета):
     - по письму — одной плашкой, как в Gmail;
     - пачками ($stack) — цепочка одной строкой, её последнее письмо, строки карточками, под пачкой один-два листа (`sheets`);
     - «Цепочки» без пачек ($plates) — цепочка своей плашкой, в ней все её письма, свежие сверху, машина — в первой строке.
     Хвост «Ещё письма…» — следующая порция (`endless`): $more — ответ хвосту, только строки (пачки, плашки) и новый хвост. --}}
@php
    $more ??= false;
    $list = match (true) { $plates => 'inbox-plates', $stack => 'inbox-piles', default => 'inbox-list' };
@endphp
@if ($rows->isEmpty() && ! $more)
    <x-ui.empty class="py-6">{{ $filtered ? 'Ничего не нашлось' : ($box === 'sent' ? 'Отправленных нет' : 'Писем нет') }}</x-ui.empty>
@else
    @unless ($more)<div class="{{ $list }}" data-controller="mail-row">@endunless
    @if ($plates)
        @foreach ($rows->groupBy('chain') as $chain)
            <div class="inbox-list">
                @foreach ($chain as $r)
                    <x-mail.inbox-row :message="$r->message" :files="$r->files" :sent="! $r->message->outside" :park="$park" :size="$loop->first ? $r->size : 1" :base="$base" :queue="$queue" :topic="$loop->first"/>
                @endforeach
            </div>
        @endforeach
    @elseif ($stack)
        @foreach ($rows as $r)
            <div class="inbox-pile">
                <x-mail.inbox-row :message="$r->message" :files="$r->files" :sent="$box === 'sent' || ($box === 'chains' && ! $r->message->outside && ! $r->peer)" :park="$park" :size="$r->size" :base="$base" :queue="$queue"
                                  :open="$r->open" :unread="$r->unread !== null" :peer="$r->peer" :mine="$box === 'chains' && ! $r->message->outside && $r->peer"/>
                @for ($i = 0; $i < $r->sheets; $i++)<span class="inbox-sheet" aria-hidden="true"></span>@endfor
            </div>
        @endforeach
    @else
        @foreach ($rows as $r)
            <x-mail.inbox-row :message="$r->message" :files="$r->files" :sent="$box === 'sent'" :park="$park" :size="$r->size" :base="$base" :queue="$queue"/>
        @endforeach
    @endif
    @if ($page->hasMorePages())
        <div class="inbox-more" data-endless-next="{{ $page->nextPageUrl() }}">Ещё письма…</div>
    @endif
    @unless ($more)</div>@endunless
@endif
