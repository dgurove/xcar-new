{{-- Срок шага одним коротким словом — для строки таблицы сотрудника (13.10.2026, владелец: «это таблица, а не эссе»).
     Рыжим — только когда нужно наше действие (`Position::needsUs`, 27.10.2026, владелец: «рыжим только если от нас конкретно
     что-то требуется»): «поставщик ответил», «отправить письмо», «выставить счёт», «осталось 3 ч», «просрочено 4 д». Ждём
     других — серым, кого и сколько: «ждём ответа 7 д», «ждём менеджера, просрочено 2 д». Кто ждёт и когда
     ушло письмо — в окне (`x-route.clock`, путь), не в строке. --}}
@props(['position'])
@php
    use App\Workflow\WaitsFor;
    $invoice = $position->awaitsInvoice();
    $letter = $invoice ? null : $position->letterStatus();
    $overdue = $position->isOverdue();
    $who = $position->waitsFor();
    $timer = fn (string $mode, $at, string $extra = '') => '<span class="nums" data-controller="timer" data-timer-'.$mode.'-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"'.$extra.'></span>';
    $left = fn () => $timer('until', $position->deadline_at, ' data-timer-done-value="-" data-timer-word-value=""');
    $wait = match ($who) {
        WaitsFor::Manager => 'ждём менеджера',
        WaitsFor::Supplier => 'ждём поставщика',
        default => null,
    };
    [$text, $hot] = match (true) {
        $invoice => [e(mb_lcfirst(\App\Workflow\Position::gapAction($position->gap(), $position->deal)['button'])), true],
        $letter === 'replied' => ['поставщик ответил', true],
        in_array($letter, ['unsent', 'failed'], true) => ['отправить письмо', true],
        $letter === 'bounced' => ['не доставлено', true],
        $letter === 'sent' => ['ждём ответа '.$timer('since', $position->letterAt()), false],
        $who === WaitsFor::Us && $position->deadline_at && $overdue => ['просрочено '.$timer('since', $position->deadline_at), true],
        $who === WaitsFor::Us && (bool) $position->deadline_at => ['осталось '.$left(), true],
        $who === WaitsFor::Us && $position->stage->timerMode() === 'stopwatch' => ['идёт '.$timer('since', $position->block_entered_at), true],
        $who === WaitsFor::Us => ['наш ход', true],
        $wait && $position->deadline_at && $overdue => [$wait.', просрочено '.$timer('since', $position->deadline_at), false],
        $wait && (bool) $position->deadline_at => [$wait.', осталось '.$left(), false],
        (bool) $wait => [$wait, false],
        default => [null, false],
    };
@endphp
@if ($text)<span {{ $attributes->class(['whitespace-nowrap', 'text-urgent' => $hot, 'text-ink-muted' => ! $hot]) }}>{!! $text !!}</span>@endif
