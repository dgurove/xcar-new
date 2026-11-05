{{-- Кто сейчас действует на шаге пути — строкой, а не фразой (04.11.2026, владелец: «во „Ждём менеджера“ непонятно, кого и чего
     ждём», «тупо текстом — это не дизайн»): менеджер сделки — аватар, имя и «Позвонить»; поставщик — логотип и имя; мы —
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
    [$state, $tone, $open] = match (true) {
        $letter === 'unsent' => ['письмо не отправлено', $overdue ? 'danger' : 'urgent', null],
        $letter === 'failed' => ['письмо не ушло', 'danger', null],
        $letter === 'bounced' => ['письмо не доставлено', 'danger', null],
        $letter === 'replied' => ['ответил '.Ago::html($position->replyAt(), true, words: false), 'accent', $letters],
        // «ждём 17 д 15 ч» — неразрывно: в узкой дорожке переносится целиком, а не «ждём 17 д / 15 ч».
        $letter === 'sent' => ['ушло '.Ago::html($position->letterAt(), words: false).' · <span class="whitespace-nowrap">ждём '.$timer('since', $position->letterAt()).'</span>', $overdue ? 'urgent' : 'plain',
            $letters.(isset($position->payload['letter_id']) ? '?at='.$position->payload['letter_id'] : '')],
        $invoice => ['счёт не выставлен', 'urgent', null],
        $position->carried() => ['везёт логистика', 'plain', null],
        (bool) $debt => ['должен&nbsp;'.e($debt->amount()).' · '.e($debt->word()), $debt->late() ? 'danger' : 'plain', null],
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
            @else
                {{-- Одной вставкой: `.state` — inline-flex, и пробелы между текстом и датой он съедал («ушло15.10»). --}}
                <x-ui.state :tone="$tone"><span>{!! $state !!}</span></x-ui.state>
            @endif
        </div>
        @if ($manager?->phone)
            <a href="tel:{{ $manager->phone }}" class="btn btn-s btn-quiet btn-round shrink-0" aria-label="Позвонить {{ $manager->shortName() }}" title="{{ $manager->phone }}"><x-ui.icon name="phone" class="size-4"/></a>
        @endif
    </div>
@endif
