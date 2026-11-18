{{-- Сделка строкой в кабинете: фото, название (номера нет с 16.11.2026); ниже — просьба к менеджеру оранжевым, если она есть, иначе чей ход
     с часами. Справа сумма и этап (у закончившейся — состояние); на телефоне правого столбика нет — сумма второй строкой,
     этап называет строка ниже. Админу — чья сделка рядом с номером, и строка ведёт в CRM: там он её и ведёт. --}}
@php
    use App\Offers\DealState;
    $offer = $deal->offer;
    $position = $offer->position();
    $active = $deal->isLive();
    $req = $active ? $deal->openRequirement : null;
    // Просьба «Оплатите счёт», а счета уже оплачены (маршрут не догнал) — не просим, пишем как есть.
    $paidUp = $req && $position?->stage->isPayStep() && $deal->fullyPaid();
    $req = $paidUp ? null : $req;
    // Ход менеджера без просьбы (ПРАЙМ без покупателя, гаражная без цены) — что сделать словами, как на странице сделки,
    // а не голое «Ваш ход» серым.
    // Гаражная — ход только просьбой (`Deal::managerTask`): продажа из гаража без просьбы — не «Укажите цену продажи» рыжим.
    $mine = $active && ! $req && ! $paidUp && ! $deal->isGarage() && $position && $position->waitsFor() === \App\Workflow\WaitsFor::Manager && ! $position->awaitsInvoice();
    $gap = $mine && $position->stage->isPayStep() && $deal->hasContract() ? $deal->invoiceGap() : null;
    $ask = $mine ? match ($gap) { 'buyer' => 'Указать покупателя', 'passport' => 'Приложить паспорт покупателя', 'price' => 'Указать цену продажи', default => $position->stage->managerTitle() } : null;
    $staff = auth()->user()->isAdmin() && $deal->buyer_id !== auth()->id();
    // Гаражная (02.11.2026, «гаражные тоже в сделках»): цена — закупочная менеджера (закреплена при подтверждении), метка
    // «Гараж», строка ведёт в машину гаража — там и шаг сделки.
    $garage = $deal->isGarage();
    // Метка «Гараж» — среди обычных сделок; в разделе «В гараже» её говорит заголовок.
    $garageTag = $garage && ! ($inGarage ?? false);
    $price = \App\Support\Money::rub($garage ? $deal->cost : $deal->amount);
    // В разделах (27.10.2026) — что сделать обычным цветом и срок (вышел — красным), у чужого хода — этап и сколько ждём;
    // сроки — счётчиками, не датами (17.11.2026, владелец: «не „с dd.mm“, а сколько прошло или сколько осталось»).
    // справа только сумма: кого ждём, говорит раздел.
    $sectioned = ($sectioned ?? false) && $active;
    $task = $sectioned ? (isset($tasks) && $tasks->has($deal->id) ? $tasks[$deal->id] : $deal->managerTask()) : null;
    // «Ваш ход» — с кнопкой ответа прямо в строке (10.11.2026, владелец: «как им понятнее и удобнее»): «Беру в работу»,
    // «Автомобиль забрал» — сразу, остальное — ссылкой на шаг с подписью, что там сделать (`x-deal.ask-action`).
    $rowAsk = $task && ! $staff ? \App\Offers\ManagerAsk::for($deal) : null;
    // Оранжевое — только просрочка того, кто смотрит: менеджеру — его ход, админу — любая (11.10.2026).
    $alarm = ! $paidUp && ($req?->due_at?->isPast() || ($position?->isOverdue() && ($staff || $position->waitsFor() === \App\Workflow\WaitsFor::Manager)));
@endphp
@if ($rowAsk)<div class="contents">@endif
<a href="{{ $staff ? $deal->crmUrl() : ($garage ? $deal->href() : '/deals/'.$deal->id) }}" class="row" @if ($staff) data-turbo="false" @endif>
    <span class="row-photo max-sm:!h-[2.625rem] max-sm:!w-14"><x-offer.photo :media="$offer->mainPhoto()" :cat="$offer->category()->icon()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        {{-- Телефон: сумма — справа в строке названия, что сейчас — под ним во всю ширину (16.11.2026: в строке статуса она
             сжимала длинную просьбу в столбик). --}}
        <span class="flex items-baseline gap-x-3">
            <span class="min-w-0 flex-1 truncate font-medium">{{ $offer->titleWithYear() }}</span>
            <span class="nums shrink-0 font-medium sm:hidden">{{ $price }}</span>
        </span>
        {{-- Номера нет (16.11.2026, владелец: «номер в списке не нужен»): метка «Гараж» и (админу) чья сделка — строкой под названием. --}}
        @if ($garageTag || $staff)
            <span class="mt-0.5 flex items-baseline gap-x-2 text-sm text-ink-muted">@if ($garageTag)<span class="tag tag-accent shrink-0">Гараж</span>@endif @if ($staff)<span class="min-w-0 truncate">{{ $deal->buyer?->shortName() }}</span>@endif</span>
        @endif
        @if ($deal->declinedBySupplier())<span class="mt-1 block text-sm text-danger">Поставщик отказал в продаже</span>@endif
        @unless ($active)<span class="nums mt-1 block text-sm text-ink-muted sm:hidden">{{ \App\Support\Ago::html(($deal->closed_at ?? $deal->updated_at), words: false) }}</span>@endunless
        @if ($task)
            @php $late = $task['due']?->isPast(); @endphp
            <span class="mt-1 block text-sm"><span class="font-medium">{{ \App\Users\Wording::mine($task['title']) }}</span>@if ($late)<span class="text-danger">, просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $task['due']->toIso8601String() }}" data-timer-coarse-value="true"></span></span>@elseif ($task['due'])<span class="text-ink-muted">, <span class="nums whitespace-nowrap" data-controller="timer" data-timer-until-value="{{ $task['due']->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::left($task['due']) }}</span></span>@endif</span>
        @elseif ($sectioned && $paidUp)
            <span class="mt-1 block text-sm text-ink-muted">Оплата получена</span>
        @elseif ($sectioned && $position)
            <span class="mt-1 block text-sm text-ink-muted">{{ \App\Users\Wording::mine($position->stage->block?->name ?? $position->stage->name) }}@if ($position->block_entered_at), <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::since($position->block_entered_at, hours: true) }}</span>@endif</span>
        @elseif ($req)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($req->title) }}@if ($req->due_at && $req->due_at->isPast()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $req->due_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($req->due_at), <span class="nums whitespace-nowrap" data-controller="timer" data-timer-until-value="{{ $req->due_at->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::left($req->due_at) }}</span>@endif</span>
        @elseif ($paidUp)
            <span class="mt-1 block text-sm text-ink-muted">Оплата получена</span>
        @elseif ($ask)
            <span class="mt-1 block text-sm font-medium text-urgent">{{ \App\Users\Wording::mine($ask) }}@if ($position->deadline_at && $position->isOverdue()), просрочено на <span class="nums whitespace-nowrap" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), <span class="nums whitespace-nowrap" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true">{{ \App\Support\Ago::left($position->deadline_at) }}</span>@endif</span>
        @elseif ($position && $active)
            <x-route.clock :position="$position" :side="$staff ? 'staff' : 'manager'" class="mt-1"/>
        @endif
    </span>
    <span class="flex max-w-[45%] shrink-0 flex-col items-end gap-1.5 max-sm:!hidden">
        <span class="nums font-medium">{{ $price }}</span>
        @if ($sectioned)
        @elseif (! $active)
            <span class="nums text-sm text-ink-muted">{{ \App\Support\Ago::html(($deal->closed_at ?? $deal->updated_at), words: false) }}</span>
        @else
            {{-- Этап в покое серый: лайм — глагол. Оранжевым становится просрочка. --}}
            <x-ui.state :tone="$alarm ? 'urgent' : 'plain'" class="max-w-full"><span class="min-w-0">{{ \App\Users\Wording::mine($position?->stage->block?->name) ?? 'Идёт работа' }}</span></x-ui.state>
        @endif
    </span>
</a>
@if ($rowAsk)
    <x-deal.ask-action :ask="$rowAsk" variant="row" class="row-ask"/>
</div>
@endif
