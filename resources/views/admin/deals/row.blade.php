{{-- Строка сделки: ТС, номер, покупатель (person=false — на его карточке не нужен), сумма, этап или состояние. --}}
@php $person ??= true; $offer = $deal->offer; $position = $offer->position(); @endphp
<a href="/work/deals/{{ $deal->id }}" class="row items-start">
    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="truncate font-medium">{{ $offer->titleWithYear() }}</span>
            <span class="nums shrink-0 text-sm font-normal text-ink-dim">№ {{ $offer->number }}</span>
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if ($person && $deal->buyer)<x-ui.person :user="$deal->buyer"/>@endif
            <span class="tag nums">{{ \App\Support\Money::rub($deal->amount) }}</span>
            @if ($deal->state !== \App\Offers\DealState::Active)
                <x-ui.pill :tone="$deal->state === \App\Offers\DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
            @elseif ($position)
                <x-ui.pill :tone="$position->isOverdue() ? 'urgent' : 'plain'">{{ $position->stage->block?->name ?? $position->stage->name }}</x-ui.pill>
                <x-route.clock :position="$position" side="staff"/>
            @else
                <x-ui.pill :tone="$offer->state->tone()">{{ $offer->state->label() }}</x-ui.pill>
            @endif
        </div>
    </div>
</a>
