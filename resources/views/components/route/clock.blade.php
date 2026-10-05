{{-- Чей ход и часы одной фразой: «Ваш ход, осталось 3ч 57м», «Ждём поставщика, идёт 46м», «просрочено на 2ч».
     Чей ход — `Position::waitsFor` (этап оплаты без счёта — наш): сотруднику «Выставить счёт», менеджеру «Готовим счёт». --}}
@props(['position', 'side' => 'manager'])
@php
    $stage = $position->stage;
    $overdue = $position->isOverdue();
    $invoice = $position->awaitsInvoice();
    $gap = $invoice ? $position->gap() : null;
    $who = $invoice ? ($side === 'manager' ? 'Готовим счёт' : ($gap === 'share' ? 'Вписать нашу долю' : 'Выставить счёт')) : match ($position->waitsFor()) {
        \App\Workflow\WaitsFor::Manager => $side === 'manager' ? 'Ваш ход' : 'Ждём менеджера',
        \App\Workflow\WaitsFor::Supplier => 'Ждём поставщика',
        \App\Workflow\WaitsFor::Us => $side === 'manager' ? 'Ждём нас' : 'Наш ход',
        default => null,
    };
@endphp
@if ($who)
    <p {{ $attributes->merge(['class' => 'text-sm '.($overdue || ($invoice && $side !== 'manager') ? 'text-urgent' : 'text-ink-muted')]) }}>
        {{ $who }}@if ($position->deadline_at && $overdue), просрочено на <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@elseif ($position->deadline_at), осталось <span class="nums font-medium" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@elseif ($stage->timerMode() === 'stopwatch'), идёт <span class="nums font-medium" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif
    </p>
@endif
