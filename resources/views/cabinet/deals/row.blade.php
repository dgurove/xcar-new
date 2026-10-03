{{-- Сделка строкой в кабинете: фото, название, №; ниже — просьба к менеджеру оранжевым, если она есть, иначе чей ход
     с часами. Справа сумма и этап (у закончившейся — состояние). Правый столбик не шире трети: длинный этап обрезается,
     на телефоне этапа нет — его называет строка ниже. --}}
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    $req = $active ? $deal->openRequirement : null;
    $alarm = $req?->due_at?->isPast() || ($position?->isOverdue() ?? false);
@endphp
<a href="/deals/{{ $deal->id }}" class="row">
    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub"><span class="tag nums">№ {{ $offer->number }}</span></span>
        @if ($req)
            <span class="mt-1 line-clamp-3 text-sm font-medium text-urgent sm:truncate">{{ $req->title }}@if ($req->due_at && $req->due_at->isPast()), просрочено на <span class="nums" data-controller="timer" data-timer-since-value="{{ $req->due_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($req->due_at), <span class="nums whitespace-nowrap">до {{ $req->due_at->translatedFormat('j M, H:i') }}</span>@endif</span>
        @elseif ($position && $active)
            <x-route.clock :position="$position" class="mt-1 line-clamp-2 sm:truncate"/>
        @endif
    </span>
    <span class="flex max-w-[45%] shrink-0 flex-col items-end gap-1.5">
        <span class="nums font-medium">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span>
        @if (! $active)
            <x-ui.state :tone="$deal->state === DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.state>
        @else
            {{-- Этап в покое серый: лайм — глагол. Оранжевым становится просрочка. --}}
            <x-ui.state :tone="$alarm ? 'urgent' : 'plain'" class="max-w-full overflow-hidden max-sm:!hidden"><span class="min-w-0 truncate">{{ $position?->stage->block?->name ?? 'Идёт работа' }}</span></x-ui.state>
        @endif
    </span>
</a>
