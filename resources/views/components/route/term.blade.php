{{-- Срок шага одним коротким словом — для строки таблицы сотрудника (13.10.2026, владелец: «это таблица, а не эссе»):
     «идёт 2 д 19 ч», «осталось 3 ч», «просрочено 4 д 18 ч», «без ответа 7 д 21 ч», «письмо не ушло», «ответили», «выставить
     счёт». Кто ждёт и когда ушло письмо — в окне (`x-route.clock`, путь), не в строке. Горит, когда ход наш или срок вышел. --}}
@props(['position'])
@php
    $invoice = $position->awaitsInvoice();
    $letter = $invoice ? null : $position->letterStatus();
    $overdue = $position->isOverdue();
    $timer = fn (string $mode, $at, string $extra = '') => '<span class="nums" data-controller="timer" data-timer-'.$mode.'-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"'.$extra.'></span>';
    [$text, $hot] = match (true) {
        $invoice => [e(mb_lcfirst(\App\Workflow\Position::gapAction($position->gap(), $position->deal)['button'])), true],
        $letter === 'unsent' => ['письмо не ушло', true],
        $letter === 'replied' => ['ответили', true],
        $letter === 'sent' => ['без ответа '.$timer('since', $position->letterAt()), $overdue],
        $position->deadline_at && $overdue => ['просрочено '.$timer('since', $position->deadline_at), true],
        (bool) $position->deadline_at => ['осталось '.$timer('until', $position->deadline_at, ' data-timer-done-value="-" data-timer-word-value=""'), false],
        $position->stage->timerMode() === 'stopwatch' => ['идёт '.$timer('since', $position->block_entered_at), $position->waitsFor() === \App\Workflow\WaitsFor::Us],
        $position->waitsFor() === \App\Workflow\WaitsFor::Us => ['наш ход', true],
        default => [null, false],
    };
@endphp
@if ($text)<span {{ $attributes->class(['whitespace-nowrap', 'text-urgent' => $hot, 'text-ink-muted' => ! $hot]) }}>{!! $text !!}</span>@endif
