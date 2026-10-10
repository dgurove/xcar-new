{{-- Сделка строкой в кабинете: фото, название, №; ниже — просьба к менеджеру оранжевым, если она есть, иначе чей ход
     с часами. Справа сумма и этап (у закончившейся — состояние); на телефоне правого столбика нет — сумма второй строкой,
     этап называет строка ниже. Админу — чья сделка рядом с номером, и строка ведёт в CRM: там он её и ведёт. --}}
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    $req = $active ? $deal->openRequirement : null;
    // Ход менеджера без просьбы (ПРАЙМ без покупателя, гаражная без цены) — что сделать словами, как на странице сделки,
    // а не голое «Ваш ход» серым.
    $mine = $active && ! $req && $position && $position->waitsFor() === \App\Workflow\WaitsFor::Manager && ! $position->awaitsInvoice();
    $gap = $mine && $position->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null;
    $ask = $mine ? match ($gap) { 'buyer' => 'Укажите покупателя', 'price' => 'Укажите цену продажи', default => $position->stage->managerTitle() } : null;
    $staff = auth()->user()->isAdmin() && $deal->buyer_id !== auth()->id();
    // Оранжевое — только просрочка того, кто смотрит: менеджеру — его ход, админу — любая (11.10.2026).
    $alarm = $req?->due_at?->isPast() || ($position?->isOverdue() && ($staff || $position->waitsFor() === \App\Workflow\WaitsFor::Manager));
@endphp
<a href="{{ $staff ? $deal->crmUrl() : '/deals/'.$deal->id }}" class="row" @if ($staff) data-turbo="false" @endif>
    <span class="row-photo max-sm:!h-[2.625rem] max-sm:!w-14"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub max-sm:!hidden"><span class="tag nums">№ {{ $offer->number }}</span>@if ($staff) {{ $deal->buyer?->shortName() }}@endif</span>
        {{-- Телефон: цена — второй строкой рядом с номером, а не колонкой справа: колонка забирала треть строки, и
             название с просьбой сжимались в узкий столбик (07.10.2026, владелец: «текст сжатый, нечитабельный»). --}}
        <span class="mt-0.5 flex items-baseline gap-x-2 sm:hidden">
            <span class="nums shrink-0 font-medium">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span>
            <span class="nums shrink-0 text-sm text-ink-muted">№ {{ $offer->number }}</span>
            @if ($staff)<span class="min-w-0 truncate text-sm text-ink-muted">{{ $deal->buyer?->shortName() }}</span>@endif
        </span>
        {{-- В архиве раздел уже называет исход: у закончившейся — только когда, у отменённой — слово (рядом отклонённые подтверждения). --}}
        @unless ($active)<span class="mt-1 block text-sm sm:hidden">@if ($deal->state === DealState::Cancelled)<span class="text-danger">{{ $deal->state->label() }}</span> @endif<span class="nums text-ink-muted">{{ ($deal->closed_at ?? $deal->updated_at)?->translatedFormat('j M') }}</span></span>@endunless
        @if ($req)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($req->title) }}@if ($req->due_at && $req->due_at->isPast()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $req->due_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($req->due_at), <span class="nums whitespace-nowrap">до {{ $req->due_at->translatedFormat('j M, H:i') }}</span>@endif</span>
        @elseif ($ask)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($ask) }}@if ($position->deadline_at && $position->isOverdue()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), <span class="nums whitespace-nowrap">до {{ $position->deadline_at->translatedFormat('j M, H:i') }}</span>@endif</span>
        @elseif ($position && $active)
            <x-route.clock :position="$position" :side="$staff ? 'staff' : 'manager'" class="mt-1"/>
        @endif
    </span>
    <span class="flex max-w-[45%] shrink-0 flex-col items-end gap-1.5 max-sm:!hidden">
        <span class="nums font-medium">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span>
        @if (! $active)
            <span class="text-sm">@if ($deal->state === DealState::Cancelled)<span class="text-danger">{{ $deal->state->label() }}</span> @endif<span class="nums text-ink-muted">{{ ($deal->closed_at ?? $deal->updated_at)?->translatedFormat('j M') }}</span></span>
        @else
            {{-- Этап в покое серый: лайм — глагол. Оранжевым становится просрочка. --}}
            <x-ui.state :tone="$alarm ? 'urgent' : 'plain'" class="max-w-full"><span class="min-w-0">{{ \App\Users\Wording::mine($position?->stage->block?->name) ?? 'Идёт работа' }}</span></x-ui.state>
        @endif
    </span>
</a>
