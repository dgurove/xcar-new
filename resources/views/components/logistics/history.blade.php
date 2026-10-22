{{-- История вывоза (18.10.2026, владелец: «кто что нажал и во сколько — Татьяна вместо исполнителя или кто-то вместо
     Татьяны, красиво и компактно»). Строка — как «История» предложения в CRM (22.10.2026, владелец): что случилось во всю
     ширину, под ним мелко «когда кто» (и «за исполнителя …», когда не он). Последние `show` записей, остальное — «Ещё N». --}}
@props(['t', 'show' => 4, 'me' => null])
@php
    // Цена логисту — наш с ним расчёт: исполнитель её не видит (ведущие и сам логист — да).
    $money = ! $me || $me->leadsLogistics() || $t->hasLogist($me);
    $events = $t->events()->with(['by', 'for'])->get()->reverse()->reject(fn ($e) => $e->type === 'cost' && ! $money)->values();
    $row = fn ($e) => '<div><div class="leading-snug">'.e($e->what()).'</div><div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-ink-dim">'
        .'<span class="nums">'.e($e->created_at->translatedFormat($e->created_at->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i')).'</span>'
        .($e->who() ? '<span>'.e($e->who()).'</span>' : '').'</div></div>';
@endphp
@if ($events->isNotEmpty())
    <div {{ $attributes->class(['flex flex-col gap-3 text-sm']) }}>
        @foreach ($events->take($show) as $e){!! $row($e) !!}@endforeach
        @if ($events->count() > $show)
            <details class="group flex flex-col gap-3">
                <summary class="cursor-pointer list-none text-ink-muted group-open:hidden">Ещё {{ $events->count() - $show }}</summary>
                <div class="flex flex-col gap-3">
                    @foreach ($events->slice($show) as $e){!! $row($e) !!}@endforeach
                </div>
            </details>
        @endif
    </div>
@endif
