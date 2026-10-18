{{-- История вывоза (18.10.2026, владелец: «кто что нажал и во сколько — Татьяна вместо исполнителя или кто-то вместо
     Татьяны, красиво и компактно»): строка — время, что случилось, справа кто нажал (и «за исполнителя …», когда не он),
     над строками дня — дата. Последние `show` записей, остальное — «Ещё N». Лесенка над ней — где задача сейчас. --}}
@props(['t', 'show' => 5, 'me' => null])
@php
    // Цена логисту — наш с ним расчёт: исполнитель её не видит (ведущие и сам логист — да).
    $money = ! $me || $me->leadsLogistics() || $t->hasLogist($me);
    $events = $t->events()->with(['by', 'for'])->get()->reverse()->reject(fn ($e) => $e->type === 'cost' && ! $money)->values();
    $row = function ($e) {
        return '<div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5"><span class="nums w-11 shrink-0 text-ink-dim">'.e($e->created_at->format('H:i')).'</span>'
            .'<span class="min-w-0 flex-1">'.e($e->what()).'</span>'
            .($e->who() ? '<span class="ml-auto text-ink-dim">'.e($e->who()).'</span>' : '').'</div>';
    };
    $days = fn ($list) => $list->groupBy(fn ($e) => $e->created_at->toDateString());
@endphp
@if ($events->isNotEmpty())
    <div {{ $attributes->class(['flex flex-col gap-3 text-sm']) }}>
        @foreach ($days($events->take($show)) as $day => $list)
            <div class="flex flex-col gap-1.5">
                <span class="text-xs text-ink-dim">{{ \App\Support\Ago::day($list->first()->created_at) }}</span>
                @foreach ($list as $e){!! $row($e) !!}@endforeach
            </div>
        @endforeach
        @if ($events->count() > $show)
            <details class="group flex flex-col gap-3">
                <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - $show }}</summary>
                <div class="flex flex-col gap-3">
                    @foreach ($days($events->slice($show)) as $day => $list)
                        <div class="flex flex-col gap-1.5">
                            <span class="text-xs text-ink-dim">{{ \App\Support\Ago::day($list->first()->created_at) }}</span>
                            @foreach ($list as $e){!! $row($e) !!}@endforeach
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif
