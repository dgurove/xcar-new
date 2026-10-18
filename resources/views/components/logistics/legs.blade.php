{{-- Отрезки вывоза через стоянки логиста (18.10.2026): когда цепочка длиннее одного — строками «откуда → куда», состояние
     и кто вёз; строка ведёт в свой отрезок, текущий отмечен. Один отрезок — ничего (его и так показывает карточка). --}}
@props(['t', 'href' => null])
@php
    $legs = $t->chain();
    $from = fn ($leg) => $leg->fromDepot ? 'Стоянка '.$leg->fromDepot->name : 'Владелец';
    $to = fn ($leg) => $leg->destination === \App\Logistics\Point::Depot ? 'Стоянка '.($leg->depot?->name ?? '') : ($leg->routeLabel() ?? 'пока неизвестно');
@endphp
@if ($legs->count() > 1)
    <div {{ $attributes->class(['list']) }}>
        @foreach ($legs as $i => $leg)
            @php
                $here = $leg->is($t);
                $when = $leg->delivered_at ? 'привёз '.\App\Support\Ago::stamp($leg->delivered_at, time: true) : $leg->next();
                $body = '<span class="nums w-5 shrink-0 text-ink-dim">'.($i + 1).'</span><span class="min-w-0 flex-1"><span class="block break-words'.($here ? ' font-medium' : '').'">'.e($from($leg).' → '.$to($leg)).'</span>'
                    .'<span class="block text-sm text-ink-muted">'.e(implode(', ', array_filter([$when, $leg->executor?->shortName()]))).'</span></span>';
            @endphp
            @if ($href && ! $here)
                <a href="{{ $href($leg) }}" class="row items-center gap-3">{!! $body !!}<x-ui.chevron/></a>
            @else
                <div @class(['row items-center gap-3', 'bg-accent-soft' => $here])>{!! $body !!}</div>
            @endif
        @endforeach
    </div>
@endif
