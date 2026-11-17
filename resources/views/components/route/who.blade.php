{{-- Кто сейчас действует на шаге пути — строкой дела (`x-route.task-row`), как строки письма и ожидания поставщика (16.11.2026,
     владелец: «глянь, как на других этапах — привести к единству»): значок, что происходит («Ждём менеджера», «Ждём счёт»,
     «Поставщик ответил», «Письмо поставщику не отправлено», «Наш ход»), ниже серым срок или дата; просрочено — красной точкой у
     значка; письмо — нажатием строки. На странице у менеджера и водителя — аватар вместо значка (`named`), в окне менеджер — в шапке.
     Слот `acts` — кнопки за менеджера под строкой во всю ширину шага. Под подпунктом «сейчас» в `x-route.path`.
     `replied` false — «Поставщик ответил» не рисуется: письмо ждёт разбора своей строкой «Письмо от … / Показать менеджеру?»
     (`x-deal.reply-review`), одно письмо двумя строками — лишнее (17.11.2026). --}}
@props(['position', 'offer', 'deal' => null, 'named' => true, 'replied' => true])
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
    $timer = fn (string $mode, $at) => '<span class="nums" data-controller="timer" data-timer-'.$mode.'-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"'.($mode === 'until' ? ' data-timer-done-value="-" data-timer-word-value=""' : '').'></span>';
    $overdue = $position->isOverdue();
    $since = $position->block_entered_at ?? $position->entered_at;
    // Шаг оплаты со счётом — сколько должен и срок счёта (`DealDebt`), как в «Оплате» и строке «Ждём менеджера».
    $debt = $side === 'manager' && $deal && $position->stage->isPayStep() ? \App\Billing\DealDebt::of($deal) : null;
    $debt = $debt && ! $debt->paid() ? $debt : null;
    // Шаг оплаты поставщику по его счёту («страховой напрямую», ДКП, 16.11.2026, владелец по Mazda 2610152114: «Менеджер должен
    // 98 000» — это наш счёт подбора, а шаг — про 1 632 000 страховой): строкой — кому и сколько платит менеджер.
    $toSupplier = $side === 'manager' && $deal && $deal->paysSupplierOn($position->stage)
        ? 'Менеджер платит '.($offer->vendor?->name ?? 'поставщику').' '.\App\Support\Money::rub((int) $deal->ownerPrice()) : null;
    $debt = $toSupplier ? null : $debt;
    // Спросили менеджера и он молчит (10.11.2026, начальник по Volvo 01527: «он пока не ответил — почему это нигде не видно») —
    // только у вопроса «Берёте в работу?»: «Заберите автомобиль» — дело, а не вопрос, там «ждём 7 д · до 05.11».
    $asked = $manager && ! $debt && ! $position->carried() && $position->stage->asksConsent()
        ? \App\Workflow\Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->latest('id')->first()
        : null;
    $asked = $asked && $asked->created_at >= $position->entered_at->copy()->subMinute() ? $asked : null;
    // Везёт наша логистика (10.11.2026, владелец: «„везёт логистика“ — непонятно, что это значит»): кто везёт — водитель или логист
    // с аватаром, состояние — что с вывозом его словами.
    $trip = $position->carried() ? \App\Logistics\Transport::openFor($offer) : null;
    $driver = $trip?->executor ?? $trip?->logist;
    $tripState = match (true) {
        ! $trip => 'вывоз не заведён',
        $trip->state === \App\Logistics\TransportState::New => 'водителя ещё не назначили',
        $trip->state === \App\Logistics\TransportState::Logist => 'ищет водителя',
        $trip->taken() => 'забрал, везёт '.mb_strtolower(mb_substr((string) $trip->routeLabel(), 0, 1)).mb_substr((string) $trip->routeLabel(), 1),
        $trip->awaitsDocs() => 'ждёт документы',
        (bool) $trip->pickup_at => 'заберёт '.$trip->pickupDay('d.m'),
        default => 'договаривается с владельцем',
    };
    // Одним видом — строкой дела (16.11.2026, владелец: «глянь, как на других этапах — привести к единству»; за образец — вариант А
    // «Ждём счёт / 2д 17ч»): значок, что происходит, ниже серым срок; просрочено — красной точкой у значка, не цветом текста;
    // нажимается — открывает письмо. [значок, заголовок, вторая строка (html), просрочено, адрес письма]
    $sinceHtml = fn ($at) => $at ? $timer('since', $at) : null;
    $late = fn () => $position->deadline_at ? '<span class="whitespace-nowrap">просрочено '.$timer('since', $position->deadline_at).'</span>' : null;
    // Что ждём от поставщика — по выходам этапа (`SupplierWait`): письмо ушло или ждём его без нашего письма.
    $what = $side === 'supplier' && ($letter === 'sent' || $letter === null) ? \App\Workflow\SupplierWait::what($position->stage) : null;
    $waitLetter = $letter === 'sent' && empty($position->payload['letter_by_hand']) ? $letters.(isset($position->payload['letter_id']) ? '?at='.$position->payload['letter_id'] : '') : null;
    [$icon, $title, $sub, $danger, $open] = match (true) {
        $letter === 'unsent' => ['send', 'Письмо поставщику не отправлено', null, $overdue, null],
        $letter === 'failed' => ['alert', 'Письмо поставщику не ушло', null, true, null],
        $letter === 'bounced' => ['alert', 'Письмо поставщику не доставлено', null, true, null],
        $letter === 'replied' => ['reply', 'Поставщик ответил', Ago::html($position->replyAt(), true, words: false), false, $letters],
        (bool) $what => ['clock', 'Ждём '.$what, $sinceHtml($letter === 'sent' ? $position->letterAt() : $since), $overdue, $waitLetter],
        $invoice => ['file', 'Счёт не выставлен', $overdue ? $late() : null, $overdue, null],
        $position->carried() => ['cat-truck', $driver?->shortName() ?? 'Вывоз', e($tripState), false, null],
        (bool) $toSupplier => ['wallet', $toSupplier, $sinceHtml($since), $overdue, null],
        (bool) $debt => ['wallet', 'Менеджер должен '.$debt->amount(), e($debt->word()), $debt->late(), null],
        (bool) $asked => ['user', 'Ждём ответ менеджера', $sinceHtml($asked->created_at), $overdue, null],
        $side === 'manager' => ['user', 'Ждём менеджера', $sinceHtml($since), $overdue, null],
        $side === 'us' => ['timer', 'Наш ход', $position->deadline_at ? ($overdue ? $late() : 'осталось '.$timer('until', $position->deadline_at)) : null, $overdue, null],
        default => ['clock', 'Ждём', $sinceHtml($since), $overdue, null],
    };
    // На странице — аватар менеджера или водителя вместо значка (в окне менеджер — в шапке).
    $face = $named ? ($manager ?? $driver) : null;
    $hasActs = isset($acts) && $acts->hasActualContent();
@endphp
@unless (! $named && $trip && $trip->state !== \App\Logistics\TransportState::Delivered)
    {{-- Окно: шаг везёт наша логистика — рейс со всем, что о нём, в дорожке «Вывоз» рядом; второй раз не пишем (12.11.2026). --}}
    @if (($side || $what) && ($replied || $letter !== 'replied'))
        <x-route.task-row :icon="$icon" :avatar="$face" :danger="$danger" :emit="$open" :static="! $open" {{ $attributes }}>
            {{ $title }}
            @if ($sub)
                <x-slot:sub>{!! $sub !!}</x-slot:sub>
            @endif
        </x-route.task-row>
    @endif
@endunless
@if ($hasActs)
    <div class="step-acts step-who-acts--bare">{{ $acts }}</div>
@endif
