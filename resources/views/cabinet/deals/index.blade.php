<x-ui.shell title="Сделки">
    @if ($deals->isEmpty())
        <div class="py-24 text-center text-ink-muted">Сделок пока нет</div>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; $position = $offer->position(); $req = $deal->openRequirement; @endphp
                <a href="/lk/sdelki/{{ $deal->id }}" class="row items-start">
                    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="truncate font-medium">{{ $offer->titleWithYear() }}</span>
                            <span class="shrink-0 text-sm text-ink-muted">№ {{ $offer->number }}</span>
                        </div>
                        <div class="text-sm font-semibold tabular-nums">{{ number_format($deal->amount, 0, '', ' ') }} ₽</div>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @if ($deal->state !== \App\Offers\DealState::Active)
                                <span class="chip {{ $deal->state === \App\Offers\DealState::Done ? 'bg-open-soft text-open' : 'bg-danger-soft text-danger' }}">{{ $deal->state->label() }}</span>
                            @elseif ($req)
                                <span class="chip bg-accent text-white">{{ $req->title }}</span>
                                @if ($req->due_at)<span class="chip tabular-nums {{ $req->due_at->isPast() ? 'bg-danger-soft text-danger' : '' }}" data-controller="timer" data-timer-until-value="{{ $req->due_at->toIso8601String() }}" data-timer-done-value="срок вышел"></span>@endif
                            @elseif ($position)
                                <span class="chip">{{ $position->stage->managerTitle() }}</span>
                            @endif
                        </div>
                    </div>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </a>
            @endforeach
        </div>
        <div class="mt-4">{{ $deals->links() }}</div>
    @endif
</x-ui.shell>
