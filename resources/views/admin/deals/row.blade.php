{{-- Строка сделки в сгруппированном списке: фото, ТС, под ним номер, покупатель (person=false — на его карточке
     не нужен) и чей ход с часами; справа сумма, под ней этап или состояние словом;
     на телефоне этап идущей сделки не показан — чей ход и часы под названием. --}}
@php
    $person ??= true;
    $offer = $deal->offer;
    $position = $offer->position();
    [$word, $tone] = match (true) {
        $deal->state !== \App\Offers\DealState::Active => [$deal->state->label(), $deal->state === \App\Offers\DealState::Done ? 'text-accent-text' : 'text-danger'],
        (bool) $position => [$position->stage->block?->name ?? $position->stage->name, $position->isOverdue() ? 'text-urgent' : 'text-ink-muted'],
        default => [$offer->state->label(), 'text-ink-muted'],
    };
@endphp
<a href="/work/deals/{{ $deal->id }}" class="row">
    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
    <div class="min-w-0 flex-1">
        <div class="truncate">{{ $offer->titleWithYear() }}</div>
        <div class="row-sub">
            <span class="nums">№ {{ $offer->number }}</span>
            @if ($person && $deal->buyer)<span>{{ $deal->buyer->name }}</span>@endif
        </div>
        @if ($deal->state === \App\Offers\DealState::Active && $position)<x-route.clock :position="$position" side="staff" class="mt-0.5 line-clamp-2 sm:truncate"/>@endif
    </div>
    <div class="shrink-0 text-right">
        <div class="nums">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</div>
        <div class="max-w-40 truncate text-sm sm:max-w-64 {{ $tone }} @if ($deal->state === \App\Offers\DealState::Active && $position) max-sm:hidden @endif">{{ $word }}</div>
    </div>
</a>
