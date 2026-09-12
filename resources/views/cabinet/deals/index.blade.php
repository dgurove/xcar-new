@php $debts = $deals->filter(fn ($d) => $d->state === \App\Offers\DealState::Active && $d->openRequirement); @endphp
<x-ui.cabinet title="Сделки" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Сделки']]">
    @if ($debts->isNotEmpty())
        <div class="mb-8">
            <h2 class="text-lg">От Вас ждут</h2>
            <div class="mt-4 space-y-3">
                @foreach ($debts as $deal)
                    @php $req = $deal->openRequirement; @endphp
                    <a href="/lk/sdelki/{{ $deal->id }}" class="box box-urgent block transition-opacity hover:opacity-90">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <span class="font-medium text-urgent">{{ $req->title }}</span>
                            @if ($req->due_at)<span class="nums shrink-0 text-sm font-normal text-urgent">{{ $req->due_at->isPast() ? 'просрочено' : 'до' }} {{ $req->due_at->format('d.m H:i') }}</span>@endif
                        </div>
                        <p class="mt-1 text-sm text-ink-muted">{{ $deal->offer->titleWithYear() }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($deals->isEmpty())
        <x-ui.empty href="/" link="В каталог">Пока ни одной сделки.</x-ui.empty>
    @else
        <div class="space-y-3">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; $position = $offer->position(); $alarm = $position?->isOverdue() ?? false; @endphp
                <a href="/lk/sdelki/{{ $deal->id }}" class="box flex flex-col gap-3 transition-colors hover:bg-hover sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
                    <span class="min-w-0 sm:flex-1">
                        <span class="block">{{ $offer->titleWithYear() }}</span>
                        <span class="nums mt-1 block text-sm font-normal text-ink-dim">№ {{ $offer->number }}</span>
                    </span>
                    <span class="flex items-center justify-between gap-3 sm:contents">
                        <span class="nums sm:text-right">{{ number_format($deal->amount, 0, '', ' ') }} ₽</span>
                        {{-- Этап в покое серый: лайм — глагол. Красным становится просрочка. --}}
                        @if ($deal->state !== \App\Offers\DealState::Active)
                            <x-ui.pill :tone="$deal->state === \App\Offers\DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
                        @else
                            <x-ui.pill :tone="$alarm ? 'urgent' : 'plain'">{{ $position?->stage->block?->name ?? 'Идёт работа' }}</x-ui.pill>
                        @endif
                    </span>
                    @if ($position && $deal->state === \App\Offers\DealState::Active)<span class="sm:w-full"><x-route.clock :position="$position"/></span>@endif
                </a>
            @endforeach
        </div>
        <div class="mt-8">{{ $deals->links() }}</div>
    @endif
</x-ui.cabinet>
