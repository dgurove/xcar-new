{{-- Срок шага одним коротким словом — для строки таблицы сотрудника (13.10.2026, владелец: «это таблица, а не эссе»).
     Обычным цветом (не серым) — только когда нужно наше действие (`Position::needsUs`, 27.10.2026, владелец: «рыжим только если от нас конкретно
     что-то требуется»): «поставщик ответил», «отправить письмо», «выставить счёт», «осталось 3 ч», «просрочено 4 д». Ждём
     других — серым, кого и сколько: «ждём ответа 7 д», «ждём менеджера, просрочено 2 д». Кто ждёт и когда
     ушло письмо — в окне (`x-route.clock`, путь), не в строке. clock=false — без часов чужого хода: на шаге оплаты срок
     говорит столбец «Оплата» сделок (`DealDebt`), второй раз его не пишем. waiting=false — без «ждём менеджера, »: во
     вкладке «Ждём менеджера» это и так сказано, а в каждой строке раздвигало таблицу. --}}
@props(['position', 'clock' => true, 'waiting' => true])
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
        $who === WaitsFor::Manager && $position->consentLate() => ['менеджер не ответил', true],
        $position->carried() => ['везёт логистика', false],
        $letter === 'sent' => ['ждём ответа '.$timer('since', $position->letterAt()), false],
        $who === WaitsFor::Us && $position->deadline_at && $overdue => ['просрочено '.$timer('since', $position->deadline_at), true],
        $who === WaitsFor::Us && (bool) $position->deadline_at => ['осталось '.$left(), true],
        $who === WaitsFor::Us && $position->stage->timerMode() === 'stopwatch' => ['идёт '.$timer('since', $position->block_entered_at), true],
        $who === WaitsFor::Us => ['наш ход', true],
        $wait && ! $clock => [$waiting ? $wait : null, false],
        $wait && $position->deadline_at && $overdue => [($waiting ? $wait.', ' : '').'просрочено '.$timer('since', $position->deadline_at), false],
        $wait && (bool) $position->deadline_at => [($waiting ? $wait.', ' : '').'осталось '.$left(), false],
        (bool) $wait => [$waiting ? $wait : null, false],
        default => [null, false],
    };
@endphp
{{-- Наше — обычным цветом, чужое — серым; рыжего нет: дела списком — во «Ваш ход» (27.10.2026). Просрочено нами — красным. --}}
@if ($text)<span {{ $attributes->class(['whitespace-nowrap', 'text-danger' => $hot && $overdue && $who === WaitsFor::Us, 'text-ink' => $hot && ! ($overdue && $who === WaitsFor::Us), 'text-ink-muted' => ! $hot]) }}>{!! $text !!}</span>@endif
