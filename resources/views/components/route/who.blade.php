{{-- Кто сейчас действует на шаге пути — строкой, а не фразой (04.11.2026, владелец: «во „Ждём менеджера“ непонятно, кого и чего
     ждём», «тупо текстом — это не дизайн»): менеджер сделки — аватар и имя («Позвонить» снята 10.11.2026 — не пользовались); поставщик — логотип и имя; мы —
     знак XCar. Справа от имени — состояние словом цвета (`x-ui.state`): у менеджера срок («до 05.11 17:00», «просрочено 2 д»,
     без срока «ждём 3 д»), у письма поставщику — что с ним («письмо не отправлено», «ушло 04.11 · ждём 1 д», «ответил 04.11»
     — нажатие открывает окно писем на нём), у нашего хода — часы. Под подпунктом «сейчас» в `x-route.path`, той же строкой
     в «Переоценке» (`.step-who`). --}}
@props(['position', 'offer', 'deal' => null])
@php
    use App\Support\Ago;
    use App\Workflow\WaitsFor;
    $waits = $position->waitsFor();
    $invoice = $position->awaitsInvoice();
    $letter = $invoice ? null : $position->letterStatus();
    $letters = '/offers/'.$offer->number.'/letters';
    // Письмо поставщику — строка поставщика, даже когда ход наш (не отправлено, ответил).
    $side = match (true) {
        $letter !== null => 'supplier',
        $position->carried(), $invoice, $waits === WaitsFor::Us => 'us',
        $waits === WaitsFor::Manager => 'manager',
        $waits === WaitsFor::Supplier => 'supplier',
        default => null,
    };
    $manager = $side === 'manager' ? $deal?->buyer : null;
    $vendor = $side === 'supplier' ? $offer->vendor : null;
    $timer = fn (string $mode, $at) => '<span class="nums" data-controller="timer" data-timer-'.$mode.'-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"'.($mode === 'until' ? ' data-timer-done-value="-" data-timer-word-value=""' : '').'></span>';
    $overdue = $position->isOverdue();
    $since = $position->block_entered_at ?? $position->entered_at;
    // Шаг оплаты со счётом — сколько должен и срок счёта (`DealDebt`), как в «Оплате» и строке «Ждём менеджера».
    $debt = $side === 'manager' && $deal && $position->stage->isPayStep() ? \App\Billing\DealDebt::of($deal) : null;
    $debt = $debt && ! $debt->paid() ? $debt : null;
    // Спросили менеджера и он молчит (10.11.2026, начальник по Volvo 01527: «он пока не ответил — почему это нигде не видно») —
    // только у вопроса «Берёте в работу?»: «Заберите автомобиль» — дело, а не вопрос, там «ждём 7 д · до 05.11».
    $asked = $manager && ! $debt && ! $position->carried() && $position->stage->asksConsent()
        ? \App\Workflow\Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->latest('id')->first()
        : null;
    $asked = $asked && $asked->created_at >= $position->entered_at->copy()->subMinute() ? $asked : null;
    [$state, $tone, $open] = match (true) {
        $letter === 'unsent' => ['письмо не отправлено', $overdue ? 'danger' : 'urgent', null],
        $letter === 'failed' => ['письмо не ушло', 'danger', null],
        $letter === 'bounced' => ['письмо не доставлено', 'danger', null],
        $letter === 'replied' => ['ответил '.Ago::html($position->replyAt(), true, words: false), 'accent', $letters],
        // «ждём 17 д 15 ч» — неразрывно: в узкой дорожке переносится целиком, а не «ждём 17 д / 15 ч».
        // Без письма — сообщили сами (`SkipLetter`, 10.11.2026): ссылки на письмо нет.
        $letter === 'sent' && ! empty($position->payload['letter_by_hand']) => ['сообщили сами '.Ago::html($position->letterAt(), words: false).' · <span class="whitespace-nowrap">ждём '.$timer('since', $position->letterAt()).'</span>', $overdue ? 'urgent' : 'plain', null],
        $letter === 'sent' => ['ушло '.Ago::html($position->letterAt(), words: false).' · <span class="whitespace-nowrap">ждём '.$timer('since', $position->letterAt()).'</span>', $overdue ? 'urgent' : 'plain',
            $letters.(isset($position->payload['letter_id']) ? '?at='.$position->payload['letter_id'] : '')],
        $invoice => ['счёт не выставлен', 'urgent', null],
        $position->carried() => ['везёт логистика', 'plain', null],
        (bool) $debt => ['должен&nbsp;'.e($debt->amount()).' · '.e($debt->word()), $debt->late() ? 'danger' : 'plain', null],
        // Ждём менеджера — сколько ждём и срок одной строкой (10.11.2026: «до 11.11» без «ждём» не говорило, что ход его).
        $side === 'manager' && (bool) $position->deadline_at => ['<span class="whitespace-nowrap">ждём '.$timer('since', $since).'</span> · '.($overdue ? '<span class="whitespace-nowrap">просрочено '.$timer('since', $position->deadline_at).'</span>' : 'до '.e(Ago::at($position->deadline_at, true, words: false))), $overdue ? 'danger' : 'plain', null],
        $overdue && (bool) $position->deadline_at => ['просрочено '.$timer('since', $position->deadline_at), 'danger', null],
        $side === 'us' && (bool) $position->deadline_at => ['осталось '.$timer('until', $position->deadline_at), 'urgent', null],
        (bool) $position->deadline_at => ['до '.e(Ago::at($position->deadline_at, true, words: false)), 'urgent', null],
        $side === 'us' => ['наш ход', 'urgent', null],
        default => ['ждём '.$timer('since', $since), 'plain', null],
    };
@endphp
@if ($side)
    <div {{ $attributes->class('step-who') }}>
        @if ($manager)
            <x-ui.avatar :user="$manager" :size="32"/>
        @elseif ($vendor)
            <span class="step-who-icon"><x-vendor.logo :vendor="$vendor" class="!size-6"/></span>
        @elseif ($side === 'us')
            <span class="step-who-icon"><x-ui.logo mark class="size-5"/></span>
        @else
            <span class="step-who-icon"><x-ui.icon name="user" class="size-4"/></span>
        @endif
        <div class="min-w-0">
            <div class="truncate">{{ $manager?->shortName() ?? $vendor?->name ?? ($side === 'us' ? 'XCar' : ($deal?->isAuction() ? 'Аукцион' : 'Менеджер')) }}</div>
            @if ($open)
                <button type="button" class="state state-{{ $tone }} underline decoration-dotted underline-offset-4" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $open }}"><span>{!! $state !!}</span></button>
            @elseif ($asked)
                <x-ui.state :tone="$overdue ? 'danger' : 'plain'"><span>спросили {!! Ago::html($asked->created_at, true, words: false) !!} · <span class="whitespace-nowrap">молчит {!! $timer('since', $asked->created_at) !!}</span>@if ($position->deadline_at) · {!! $overdue ? '<span class="whitespace-nowrap">просрочено '.$timer('since', $position->deadline_at).'</span>' : 'до '.e(Ago::at($position->deadline_at, true, words: false)) !!}@endif</span></x-ui.state>
            @else
                {{-- Одной вставкой: `.state` — inline-flex, и пробелы между текстом и датой он съедал («ушло15.10»). --}}
                <x-ui.state :tone="$tone"><span>{!! $state !!}</span></x-ui.state>
            @endif
        </div>
    </div>
@endif
