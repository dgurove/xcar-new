<x-ui.shell title="Закупки">
    @if ($purchases->isEmpty())
        <div class="py-24 text-center text-ink-muted">Открытых закупок нет</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($purchases as $p)
                <a href="/zakupki/{{ $p->number }}" class="row items-start">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $p->publicTitle() }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                            <span class="chip {{ $p->acceptsOffers() ? 'bg-open-soft text-open' : 'bg-closed-soft text-closed' }}">{{ $p->acceptsOffers() ? 'Приём цен' : 'Приём закрыт' }}</span>
                            <span class="chip tabular-nums">{{ $p->cars_count }} машин</span>
                            @if ($mine[$p->id] ?? 0)<span class="chip bg-accent-soft text-accent-text tabular-nums">моих цен: {{ $mine[$p->id] }}</span>@endif
                            @if ($p->acceptsOffers() && $p->offers_close_at)<span class="chip tabular-nums" data-controller="timer" data-timer-until-value="{{ $p->offers_close_at->toIso8601String() }}"></span>@endif
                        </div>
                    </div>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
    @endif
</x-ui.shell>
