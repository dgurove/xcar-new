{{-- Чей ход и часы одной фразой: «Ваш ход, осталось 3ч 57м», «Ждём поставщика, идёт 46м», «просрочено на 2ч».
     Чей ход — `Position::waitsFor` (этап оплаты без счёта — наш): сотруднику «Выставить счёт», менеджеру «Готовим счёт». --}}
{{-- `bare` — только часы («осталось 3 ч 55 мин»): шапка шага сама говорит, что сделать (согласие менеджера). --}}
{{-- Менеджеру часы и оранжевое — только у его хода (11.10.2026, владелец: «зачем рыжим „просрочено“, если ждём
     поставщика, менеджер что с этим может сделать?»): чужой ход — серым словом без часов. Сотруднику — все часы. --}}
@props(['position', 'side' => 'manager', 'bare' => false])
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
    <p {{ $attributes->merge(['class' => 'text-sm '.($letter === 'sent' && ! $position->isOverdue() ? 'text-ink-muted' : 'text-urgent')]) }}>
        @if ($letter === 'unsent')Письмо поставщику не отправлено, идёт <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>
        @elseif ($letter === 'replied')Поставщик ответил {{ $position->replyAt()->translatedFormat('j M, H:i') }}
        @else Письмо ушло {{ $position->letterAt()->translatedFormat('j M, H:i') }}, ответа нет <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->letterAt()->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
    </p>
@elseif ($foreign && ! $bare)
    @if ($who)<p {{ $attributes->merge(['class' => 'text-sm text-ink-muted']) }}>{{ $who }}</p>@endif
@elseif ($who && ! ($bare && ! $position->deadline_at))
    <p {{ $attributes->merge(['class' => 'text-sm '.($overdue || ($invoice && $side !== 'manager') || ($side === 'manager' && ! $foreign) ? 'text-urgent' : 'text-ink-muted')]) }}>
        @if ($bare)@if ($overdue)Просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@else Осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@endif
        @else{{ $who }}@if ($position->deadline_at && $overdue), просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@elseif ($stage->timerMode() === 'stopwatch'), идёт <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
        @endif
    </p>
@endif
