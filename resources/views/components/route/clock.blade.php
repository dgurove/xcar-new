{{-- Чей ход и часы одной фразой: «Ваш ход, осталось 3ч 57м», «Ждём поставщика, идёт 46м», «просрочено на 2ч».
     Чей ход — `Position::waitsFor` (этап оплаты без счёта — наш): сотруднику «Выставить счёт», менеджеру «Готовим счёт». --}}
{{-- `bare` — только часы («осталось 3 ч 55 мин»): шапка шага сама говорит, что сделать (согласие менеджера). --}}
{{-- Менеджеру часы и оранжевое — только у его хода (11.10.2026, владелец: «зачем рыжим „просрочено“, если ждём
     поставщика, менеджер что с этим может сделать?»): чужой ход — серым словом без часов. Сотруднику — все часы. --}}
{{-- letters — окно писем машины (шаг пути): «Письмо ушло 9 окт, 12:59» открывает его на этом письме, «Поставщик ответил» —
     на непрочитанном. В строке таблицы ссылки нет — строка сама ссылка. --}}
@props(['position', 'side' => 'manager', 'bare' => false, 'letters' => null])
@php
    $stage = $position->stage;
    $invoice = $position->awaitsInvoice();
    $foreign = $side === 'manager' && ($invoice || $position->waitsFor() !== \App\Workflow\WaitsFor::Manager);
    $overdue = ! $foreign && $position->isOverdue();
    $gap = $invoice ? $position->gap() : null;
    $who = $invoice ? ($side === 'manager' ? 'Готовим счёт' : \App\Workflow\Position::gapAction($gap, $position->deal)['button']) : match ($position->waitsFor()) {
        \App\Workflow\WaitsFor::Manager => $side === 'manager' ? 'Ваш ход' : 'Ждём менеджера',
        \App\Workflow\WaitsFor::Supplier => 'Ждём поставщика',
        \App\Workflow\WaitsFor::Us => $side === 'manager' ? 'Ждём XCar' : 'Наш ход',
        default => null,
    };
@endphp
@php
    // Этап с письмом поставщику — сотруднику словами про письмо (11.10.2026, начальник не понимал, ушло ли оно):
    // «Письмо поставщику не отправлено», «Письмо ушло 9 окт, 12:59, ответа нет 1 д 8 ч», «Поставщик ответил …».
    $letter = $side === 'staff' && ! $invoice ? $position->letterStatus() : null;
@endphp
@if ($letter)
    <p {{ $attributes->merge(['class' => 'text-sm '.(in_array($letter, ['failed', 'bounced'], true) ? 'text-danger' : ($letter === 'sent' && ! $position->isOverdue() ? 'text-ink-muted' : 'text-urgent'))]) }}>
        {{-- Доставка (18.10.2026): не ушло с нашей стороны — причина; почтовый сервер вернул отказ — куда не дошло. --}}
        @if ($letter === 'failed')Письмо поставщику не ушло{{ ($e = $position->payload['letter_failed']['error'] ?? '') !== '' ? ': '.$e : '' }}
        @elseif ($letter === 'bounced')Письмо не доставлено{{ ($to = $position->payload['letter_bounced']['to'] ?? '') !== '' ? ' на '.$to : '' }}
        {{-- Не ушло — со своим сроком (18.10.2026: письма висели по 5 дней без часов): «осталось», потом «просрочено на». --}}
        @elseif ($letter === 'unsent')Письмо поставщику не отправлено@if ($position->deadline_at && $position->isOverdue()), просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@else, идёт <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
        @elseif ($letter === 'replied')@if ($letters)<button type="button" class="underline decoration-dotted underline-offset-4" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}">Поставщик ответил {{ $position->replyAt()->translatedFormat('j M, H:i') }}</button>@else Поставщик ответил {{ $position->replyAt()->translatedFormat('j M, H:i') }}@endif
        @else @if ($letters && isset($position->payload['letter_id']))<button type="button" class="underline decoration-dotted underline-offset-4" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $letters }}?at={{ $position->payload['letter_id'] }}">Письмо ушло {{ $position->letterAt()->translatedFormat('j M, H:i') }}</button>@else Письмо ушло {{ $position->letterAt()->translatedFormat('j M, H:i') }}@endif, ответа нет <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->letterAt()->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
    </p>
@elseif ($foreign && ! $bare)
    {{-- С какого числа ждём (27.10.2026: BMW X6 22 дня «ждём поставщика» без даты — непонятно, давно ли). --}}
    @if ($who)<p {{ $attributes->merge(['class' => 'text-sm text-ink-muted']) }}>{{ $who }}@if ($position->block_entered_at) с <span class="nums">{{ \App\Support\Ago::day($position->block_entered_at) }}</span>@endif</p>@endif
@elseif ($who && ! ($bare && ! $position->deadline_at))
    <p {{ $attributes->merge(['class' => 'text-sm '.($overdue || ($invoice && $side !== 'manager') || ($side === 'manager' && ! $foreign) ? 'text-urgent' : 'text-ink-muted')]) }}>
        @if ($bare)@if ($overdue)Просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@else Осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@endif
        @else{{ $who }}@if ($position->deadline_at && $overdue), просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@elseif ($stage->timerMode() === 'stopwatch'), идёт <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
        @endif
    </p>
@endif
