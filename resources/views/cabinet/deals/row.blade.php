{{-- Сделка строкой в кабинете: фото, название, №; ниже — просьба к менеджеру оранжевым, если она есть, иначе чей ход
     с часами. Справа сумма и этап (у закончившейся — состояние); на телефоне правого столбика нет — сумма второй строкой,
     этап называет строка ниже. Админу — чья сделка рядом с номером, и строка ведёт в CRM: там он её и ведёт. --}}
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->state === DealState::Active;
    $req = $active ? $deal->openRequirement : null;
    // Просьба «Оплатите счёт», а счета уже оплачены (маршрут не догнал) — не просим, пишем как есть.
    $paidUp = $req && $position?->stage->isPayStep() && $deal->fullyPaid();
    $req = $paidUp ? null : $req;
    // Ход менеджера без просьбы (ПРАЙМ без покупателя, гаражная без цены) — что сделать словами, как на странице сделки,
    // а не голое «Ваш ход» серым.
    $mine = $active && ! $req && ! $paidUp && $position && $position->waitsFor() === \App\Workflow\WaitsFor::Manager && ! $position->awaitsInvoice();
    $gap = $mine && $position->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null;
    $ask = $mine ? match ($gap) { 'buyer' => 'Укажите покупателя', 'passport' => 'Приложите паспорт покупателя', 'price' => 'Укажите цену продажи', default => $position->stage->managerTitle() } : null;
    $staff = auth()->user()->isAdmin() && $deal->buyer_id !== auth()->id();
    // В разделах (27.10.2026) — что сделать обычным цветом и срок (вышел — красным), у чужого хода — этап и с какого дня;
    // справа только сумма: кого ждём, говорит раздел.
    $sectioned = ($sectioned ?? false) && $active;
    $task = $sectioned ? $deal->managerTask() : null;
    // Оранжевое — только просрочка того, кто смотрит: менеджеру — его ход, админу — любая (11.10.2026).
    $alarm = ! $paidUp && ($req?->due_at?->isPast() || ($position?->isOverdue() && ($staff || $position->waitsFor() === \App\Workflow\WaitsFor::Manager)));
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
        {{-- В архиве исход называет раздел («Закончены», «Отменены») — у строки только когда. --}}
        @unless ($active)<span class="nums mt-1 block text-sm text-ink-muted sm:hidden">{{ \App\Support\Ago::html(($deal->closed_at ?? $deal->updated_at), words: false) }}</span>@endunless
        @if ($deal->declinedBySupplier())<span class="mt-1 block text-sm text-danger">Поставщик отказал в продаже</span>@endif
        @if ($task)
            @php $late = $task['due']?->isPast(); @endphp
            <span class="mt-1 block text-sm"><span class="font-medium">{{ \App\Users\Wording::mine($task['title']) }}</span>@if ($late)<span class="text-danger">, просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $task['due']->toIso8601String() }}" data-timer-coarse-value="true"></span></span>@elseif ($task['due'])<span class="text-ink-muted">, <span class="nums whitespace-nowrap">до {{ \App\Support\Ago::html($task['due'], true, words: false) }}</span></span>@endif</span>
        @elseif ($sectioned && $paidUp)
            <span class="mt-1 block text-sm text-ink-muted">Оплата получена</span>
        @elseif ($sectioned && $position)
            <span class="mt-1 block text-sm text-ink-muted">{{ \App\Users\Wording::mine($position->stage->block?->name ?? $position->stage->name) }}@if ($position->block_entered_at) <span class="nums whitespace-nowrap">с {{ \App\Support\Ago::html($position->block_entered_at, words: false) }}</span>@endif</span>
        @elseif ($req)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($req->title) }}@if ($req->due_at && $req->due_at->isPast()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $req->due_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($req->due_at), <span class="nums whitespace-nowrap">до {{ \App\Support\Ago::html($req->due_at, true, words: false) }}</span>@endif</span>
        @elseif ($paidUp)
            <span class="mt-1 block text-sm text-ink-muted">Оплата получена</span>
        @elseif ($ask)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($ask) }}@if ($position->deadline_at && $position->isOverdue()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), <span class="nums whitespace-nowrap">до {{ \App\Support\Ago::html($position->deadline_at, true, words: false) }}</span>@endif</span>
        @elseif ($position && $active)
            <x-route.clock :position="$position" :side="$staff ? 'staff' : 'manager'" class="mt-1"/>
        @endif
    </span>
    <span class="flex max-w-[45%] shrink-0 flex-col items-end gap-1.5 max-sm:!hidden">
        <span class="nums font-medium">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</span>
        @if ($sectioned)
        @elseif (! $active)
            <span class="nums text-sm text-ink-muted">{{ \App\Support\Ago::html(($deal->closed_at ?? $deal->updated_at), words: false) }}</span>
        @else
            {{-- Этап в покое серый: лайм — глагол. Оранжевым становится просрочка. --}}
            <x-ui.state :tone="$alarm ? 'urgent' : 'plain'" class="max-w-full"><span class="min-w-0">{{ \App\Users\Wording::mine($position?->stage->block?->name) ?? 'Идёт работа' }}</span></x-ui.state>
        @endif
    </span>
</a>
