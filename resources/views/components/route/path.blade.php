{{-- Путь ветки маршрута в CRM — как таймлайн дела ТС на парковке (.steps): пройденные блоки галочкой с датой (раскрываются —
     этапы по журналу, ответы менеджера с файлами и «Вернуть на этот шаг»), текущий раскрыт — этап, чей ход и часы словами,
     просьба к менеджеру, действия: главный выход лаймовый, остальные серые, письмо вендору; срывы в тупик («Отказ
     поставщика») — в «···» с подтверждением. Впереди — серым, пока путь однозначен (Workflow\Path).
     Деньги в шаге, а не рядом: на оплате, пока счёта нет, главное действие — «Выставить счёт»; менеджер сообщил об
     оплате — его заявка тут же с «Поступило» / «Не поступила» (подтверждение само двигает путь, AdvanceOnPayment),
     и выходов «Оплата получена / не поступила» рядом нет — одно действие, а не два несвязанных. Слот — шаги после
     маршрута тем же видом (продажа гаражной машины).
     07.10.2026: поля строками (.fields), кнопка .sheet-foot. --}}
@props(['offer', 'position'])
@php
    use App\Billing\InvoiceState;
    use App\Support\Money;
    use App\Workflow\{Actor, Path, WaitsFor};
    $n = $offer->number;
    $stage = $position->stage;
    $path = Path::for($offer, $position->track);
    // «Подтверждение принято» — не кнопка: в сделку ведёт только «Принять» у подтверждения.
    // Деньги сделки на этапах оплаты; её же ветка решает, какие кнопки видны (гараж «платим мы» — гаражные).
    $deal = $offer->deal?->isActive() ? $offer->deal : null;
    // Исход, что ведёт на согласие менеджера (Альфа), — «Спросить менеджера» (27.10.2026, владелец: «Поставщик согласовал»
    // читали как «сразу в работу», а это только вопрос менеджеру). Подпись исхода в маршруте не меняется — по ней узнают шаг.
    $asks = fn ($x) => $x->actor === Actor::Staff && $x->to?->asksConsent();
    $label = fn ($x) => $asks($x) ? 'Спросить менеджера' : $x->label;
    $exits = $stage->exitsFor(Actor::Staff, $deal)->reject(fn ($x) => $x->acceptsBid());
    // «За менеджера» (28.10.2026, владелец: «он мне уже устно подтвердил»): его ответы шага — пунктами «···», нажимает
    // админ (`RouteController::forManager`, в «Истории» — «за менеджера»).
    $forManager = $deal?->buyer_id && auth()->user()?->canManageCrm() ? $stage->exitsFor(Actor::Manager, $deal) : collect();
    $takeFor = $stage->asksConsent() ? $forManager->first(fn ($x) => $x->takes()) : null;
    // Имена блоков как есть: кто вывозит, видно строкой над путём («Стоит у Сергей К.» имя не склоняет).
    $blockName = fn ($block) => $block->name;
    [$breaks, $moves] = $exits->partition(fn ($x) => $x->to?->block && $x->to->block_id !== $stage->block_id && $x->to->block->isDeadEnd())->all();
    // «Заявка отправлена» (Каркаде) — не кнопка: её жмёт само письмо заявки по всем сделкам вендора разом.
    $requestExit = $deal ? $moves->first(fn ($x) => mb_strtolower($x->label) === 'заявка отправлена') : null;
    $requests = $requestExit ? \App\Offers\Deal::awaitingRequest($offer->vendor_id)->count() : 0;
    if ($requestExit) {
        $moves = $moves->reject(fn ($x) => $x->is($requestExit));
    }
    $overdue = $position->isOverdue();
    $tone = $overdue ? 'text-danger' : match ($position->waitsFor()->tone()) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' };
    $clock = $position->deadline_at || $stage->timerMode() === 'stopwatch';
    $requirements = $offer->requirements()->with(['media', 'stage'])->get();
    // Кто отвечал за менеджера («(за него …)») — одним запросом на путь, а не на каждый ответ.
    $answeredBy = \App\Users\User::whereIn('id', $requirements->map(fn ($r) => $r->answer['by'] ?? null)->filter()->unique())->get()->keyBy('id');
    $menu = 'route-more-'.$position->id;
    $invoices = $deal ? $deal->invoices()->with('claims.media')->get()->reject(fn ($i) => $i->isOwed() || $i->state === InvoiceState::Void) : collect();
    // Этап оплаты без счёта — ход наш (`Position::awaitsInvoice`): «Выставить счёт» первой кнопкой.
    $wantsInvoice = $deal && $position->awaitsInvoice();
    $paidExit = fn ($x) => preg_match('/^оплата (получена|не поступила)/u', mb_strtolower($x->label)) === 1;
    $claims = $moves->contains($paidExit) ? $invoices->flatMap(fn ($i) => $i->claims->map(fn ($p) => [$i, $p])) : collect();
    if ($claims->isNotEmpty()) {
        $moves = $moves->reject($paidExit);
    }
    // Деньги пришли мимо менеджера (наличными, на карту, увидели в банке) — отметить прямо в шаге оплаты, не ища счёт в
    // «Оплатах»: оплата сама проходит шаг за менеджера и наш «Оплата получена» (`AdvanceOnPayment`). 13.10.2026, владелец.
    $payNow = $deal && $stage->isPayStep() && $claims->isEmpty() && auth()->user()?->canManageCrm()
        ? $invoices->filter(fn ($i) => $i->state === InvoiceState::Issued && $i->remaining() > 0)->values()
        : collect();
    $undo = \App\Workflow\Actions\StepBack::undoable($offer, $position->track);
    // «Продлить срок» (19.01.2027, начальник): шагу с часами — +1 день, +3 дня, до даты (`ExtendDeadline`).
    $extend = $position->deadline_at !== null && auth()->user()?->canManageCrm();
    // Черновик без цены продажи (владелец 04.10.2026): первый шаг продажи — назначить цену, а не «Опубликовать». Шаг не
    // из маршрута — он стоит перед ним текущим с «Оценить» (`/offers/{n}/rate`), а этап маршрута ждёт впереди серым.
    $pricing = $position->track === \App\Workflow\Track::Sale && $offer->state === \App\Offers\OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled() && auth()->user()?->canManageCrm();
@endphp
<div class="steps">
    @if ($pricing)
        <div class="step step--{{ Path::CURRENT }}">
            <span class="step-dot"></span>
            <div class="step-body">
                <div class="step-head"><span class="step-title">Назначение цены</span></div>
                <p class="step-hint text-urgent">Ждём нас</p>
                <form method="post" action="/offers/{{ $n }}/rate" class="mt-3 flex max-w-sm gap-2" data-controller="bid" data-bid-asking-value="0">
                    @csrf
                    <input type="hidden" name="asking_price" data-bid-target="amount" value="">
                    <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽" data-bid-target="display" data-action="input->bid#input" data-save-into="offer-form" data-save-name="asking_price">
                    <x-ui.button size="sm" class="shrink-0">Оценить</x-ui.button>
                </form>
            </div>
        </div>
    @endif
    @foreach ($path as $step)
        @php
            // Пока цена не назначена, этап маршрута — впереди, без кнопок.
            if ($pricing && $step['state'] === Path::CURRENT) $step['state'] = Path::NEXT;
            $block = $step['block'];
            // Только то, что менеджер ответил сам (есть выход): просьбу, закрытую сменой этапа, отменой или оплатой мимо него
            // (`closed_by`), «Менеджер: …» не подписываем.
            $answers = $requirements->filter(fn ($r) => $r->done_at && ! empty($r->answer['exit']) && $r->stage?->block_id === $block->id)
                // Вернулись на этап («Оплата не поступила») — прежний ответ на нём уже не в силе, в текущем шаге его нет.
                ->reject(fn ($r) => $step['state'] === Path::CURRENT && $r->stage_id === $position->stage_id && $r->done_at < $position->entered_at)
                ->sortBy('done_at');
        @endphp
        <div class="step step--{{ $step['state'] }}">
            <span class="step-dot">@if ($step['state'] === Path::DONE)<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                @if ($step['state'] === Path::DONE)
                    <details class="step-details">
                        <summary class="step-head">
                            <span class="step-title flex-1">{{ $blockName($block) }}</span>
                            <span class="flex shrink-0 items-center gap-1.5">@if ($step['at'])<span class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($step['at'], words: false) }}</span>@endif<x-ui.icon name="chevron-down" class="step-chevron size-4 text-ink-dim"/></span>
                        </summary>
                        <div class="mt-1 flex flex-col gap-1">
                            @foreach ($step['stages'] as $s)
                                <div class="flex flex-wrap items-baseline gap-x-2 text-sm"><span>{{ $s['stage'] }}</span><span class="nums text-ink-dim">{{ \App\Support\Ago::html($s['at'], true, words: false) }}</span></div>
                            @endforeach
                            @foreach ($answers as $r)
                                <div class="text-sm"><span class="text-ink-muted">Менеджер:</span> {{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif <span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, true, words: false) }}</span></div>
                                @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                                @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                            @endforeach
                            {{-- Назад — на последний этап блока, где предложение было, а не на первый. --}}
                            @php $lastIn = $step['stages']->last(); $target = $lastIn ? ($block->stages->firstWhere('id', $lastIn['stage_id']) ?? $block->stages->firstWhere('name', $lastIn['stage'])) : null; $target ??= $block->stages->first(); @endphp
                            @if ($target && $block->id !== $stage->block_id)
                                <form method="post" action="/offers/{{ $n }}/stage" class="mt-1" data-turbo-confirm="Вернуть на «{{ $target->name }}»?">
                                    @csrf<input type="hidden" name="stage_id" value="{{ $target->id }}">
                                    <x-ui.button size="sm" variant="secondary">Вернуть на этот шаг</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </details>
                @elseif ($step['state'] === Path::CURRENT)
                    {{-- «···» — справа от названия и в узкой колонке: длинное название переносится, а не выталкивает кнопку. --}}
                    <div class="step-head !flex-nowrap !items-start">
                        <span class="step-title min-w-0 flex-1">{{ $blockName($block) }}</span>
                        @if ($breaks->isNotEmpty() || $undo || $extend || $forManager->isNotEmpty())
                            <div class="contents" data-controller="menu">
                                <button type="button" class="btn btn-s btn-quiet btn-round -my-1 ml-auto shrink-0" data-action="menu#toggle" aria-label="Ещё" aria-haspopup="menu" aria-controls="{{ $menu }}"><x-ui.icon name="more" class="size-5"/></button>
                                <div id="{{ $menu }}" class="menu" popover data-menu-target="list" role="menu">
                                    {{-- Отмена шага, которым сюда пришли (StepBack); сразу после нажатия она же — «Отменить» в тосте. --}}
                                    @if ($undo)
                                        @php $undoText = $undo['label'] ? 'Отменить «'.$undo['label'].'»' : 'Вернуть на «'.$undo['to']->name.'»'; @endphp
                                        <x-ui.step-back :action="'/offers/'.$n.'/back'" :label="$undoText" :track="$position->track->value" :text="'Вернётся «'.$undo['to']->name.'»'"/>
                                    @endif
                                    @if ($extend)
                                        @foreach ([1 => 'Продлить на день', 3 => 'Продлить на 3 дня'] as $days => $extendLabel)
                                            <form method="post" action="/offers/{{ $n }}/deadline" class="contents">@csrf<input type="hidden" name="track" value="{{ $position->track->value }}"><input type="hidden" name="days" value="{{ $days }}"><button class="menu-item w-full" role="menuitem">{{ $extendLabel }}</button></form>
                                        @endforeach
                                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="deadline-{{ $position->id }}:open">Срок до даты…</button>
                                    @endif
                                    @foreach ($forManager as $exit)
                                        {{-- Уход в продажу («Я передумал») отменяет сделку — переспрашиваем; остальное — сразу. --}}
                                        @php $retreat = $exit->isRetreat(); @endphp
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" @if ($retreat) data-turbo-confirm="За менеджера: «{{ $exit->label }}»?" data-turbo-confirm-text="Сделка отменится, ТС вернётся в продажу" data-turbo-confirm-label="Отменить сделку" @endif>
                                            @csrf<input type="hidden" name="for" value="manager">
                                            <button class="menu-item w-full {{ $retreat ? 'text-danger' : '' }}" role="menuitem"><x-ui.icon name="user" class="size-4"/>За менеджера: «{{ $exit->label }}»</button>
                                        </form>
                                    @endforeach
                                    @foreach ($breaks as $exit)
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" data-turbo-confirm="{{ $exit->confirm ?: $exit->label.'?' }}">@csrf<button class="menu-item w-full text-danger" role="menuitem">{{ $label($exit) }}</button></form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if ($extend)
                            <div class="contents" data-controller="sheet" data-action="deadline-{{ $position->id }}:open@window->sheet#open">
                                <x-ui.sheet id="deadline-{{ $position->id }}" title="Срок шага">
                                    <form method="post" action="/offers/{{ $n }}/deadline" class="flex flex-col gap-4">
                                        @csrf<input type="hidden" name="track" value="{{ $position->track->value }}">
                                        <div class="fields"><x-ui.field name="until" :id="'deadline-until-'.$position->id" label="До" type="datetime-local" :value="$position->deadline_at->format('Y-m-d\TH:i')" :min="now()->format('Y-m-d\TH:i')" required/></div>
                                        <x-ui.sheet-foot>Продлить</x-ui.sheet-foot>
                                    </form>
                                </x-ui.sheet>
                            </div>
                        @endif
                    </div>
                    @if ($wantsInvoice)<div>Счёт ещё не выставлен</div>
                    {{-- Имя этапа с письмом («Уведомили поставщика о покупке») читалось как «письмо ушло», даже когда его не
                         отправляли (11.10.2026) — что с письмом, говорит строка ниже. --}}
                    @elseif ($stage->name !== $block->name && ! $position->letterStatus())<div>{{ $stage->name }}</div>@endif
                    @php
                        // Что ждём от менеджера — его ответами, а не текстом просьбы: «Подтвердите покупку» в CRM читалось как
                        // команда админу (05.10.2026, «что такое „проверьте покупку“?»). Это и есть подсказка шага — одной строкой
                        // с таймером: «Ждём менеджера, осталось…» и следом «Ждём от менеджера «…» до …» говорили одно дважды (07.10.2026).
                        $req = ! $wantsInvoice && $stage->awaitsManager($deal) ? $requirements->first(fn ($r) => ! $r->done_at && $r->stage_id === $stage->id) : null;
                        $replies = $req ? $stage->exitsFor(\App\Workflow\Actor::Manager, $deal)->pluck('label')->map(fn ($l) => '«'.$l.'»') : collect();
                        $waitsWord = $replies->count() > 1 ? 'Менеджер ответит '.$replies->slice(0, -1)->implode(', ').' или '.$replies->last() : ($replies->isNotEmpty() ? 'Ждём от менеджера '.$replies->first() : null);
                        // Шаг просит документ — что именно, а не имя кнопки (19.10.2026: «Ждём от менеджера «Договор приложен»»).
                        if ($req?->asks === \App\Workflow\Asks::Document) {
                            $waitsWord = 'Ждём от менеджера '.match (true) {
                                (bool) preg_match('/договор/iu', $stage->name.' '.$req->title) => 'подписанный договор',
                                (bool) preg_match('/плат[её]ж/iu', (string) $req->title) => 'платёжку',
                                default => 'документ',
                            };
                        }
                    @endphp
                    @if ($position->letterStatus())
                        {{-- Этап с письмом поставщику — что с письмом, а не «Ждём поставщика» (`x-route.clock`, 11.10.2026). --}}
                        <x-route.clock :position="$position" side="staff" class="step-hint" :letters="'/offers/'.$offer->number.'/letters'"/>
                    @elseif (! $wantsInvoice && ($overdue || $position->waitsFor() !== WaitsFor::Nobody || $clock || $waitsWord))
                        {{-- Одной строкой: переносы в разметке давали пробел перед запятой. --}}
                        <p class="step-hint {{ $tone }}">@if ($overdue)Срок вышел <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-coarse-value="true"></span> назад@else{{ $waitsWord ?? $position->waitsFor()->label() }}@if ($position->deadline_at), осталось <span class="nums" data-controller="timer" data-timer-until-value="{{ $position->deadline_at->toIso8601String() }}" data-timer-done-value="-" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($position->deadline_at, '') }}</span>@elseif ($req?->due_at), до <span class="nums">{{ \App\Support\Ago::html($req->due_at, true, words: false) }}</span>@elseif ($stage->timerMode() === 'stopwatch'), идёт <span class="nums" data-controller="timer" data-timer-since-value="{{ $position->block_entered_at->toIso8601String() }}" data-timer-coarse-value="true"></span>@endif @endif</p>
                    @endif
                    {{-- Согласие менеджера: чаще всего он уже сказал «да» устно — «Беру в работу» за него видной тихой кнопкой. --}}
                    @if ($takeFor)
                        <form method="post" action="/offers/{{ $n }}/exit/{{ $takeFor->id }}" class="mt-2">
                            @csrf<input type="hidden" name="for" value="manager">
                            {{-- В узкой дорожке подпись переносится, а не вылезает за карточку. --}}
                            <x-ui.button size="sm" variant="secondary" class="!h-auto min-h-9 max-w-full whitespace-normal py-1.5 text-left">«{{ $takeFor->label }}» за менеджера</x-ui.button>
                        </form>
                    @endif
                    {{-- Менеджер уже приложил, но шаг ещё не отметил — файл видно сразу. --}}
                    @if ($req && $req->getMedia('files')->isNotEmpty())
                        <div class="mt-2 flex flex-col gap-1">@foreach ($req->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach</div>
                    @endif
                    {{-- ДКП: что вписываем мы (продавец по СТС, документы ТС, цена) — прямо в шаге, «Заполнить» открывает карточку «ДКП»
                         сразу полями (17.10.2026, владелец: «нет возможности указать данные продавца, всё встало» — поля жили за
                         «Изменить» внизу дорожки «Сделка»). Покупателя вносит менеджер — его тут нет. --}}
                    @if ($deal?->isActive() && $deal->hasContract() && auth()->user()?->canManageCrm()
                        && ($ours = array_values(array_diff(\App\Offers\DealContract::for($deal)->missing(), ['покупатель', 'данные покупателя', 'скан паспорта покупателя']))))
                        <p class="step-hint text-urgent">ДКП ждёт: {{ implode(', ', $ours) }} <a href="/offers/{{ $offer->number }}?dkp=1#dkp" class="text-accent-text underline-offset-4" data-turbo-frame="_top">Заполнить</a></p>
                    @endif
                    @if ($position->fields())
                        <div class="mt-2 text-sm">@foreach ($position->fields() as $k => $v)<div><span class="text-ink-muted">{{ collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach</div>
                    @endif
                    @foreach ($answers as $r)
                        <div class="mt-1 text-sm"><span class="text-ink-muted">Менеджер:</span> {{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif</div>
                        @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                        @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :size="$f->humanReadableSize" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                    @endforeach
                    @foreach ($claims as [$invoice, $p])
                        <div class="mt-3 rounded-(--radius-m) bg-surface-2 p-3">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-sm text-ink-muted">Менеджер сообщил об оплате</span>
                                <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                                <span class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($p->paid_at, words: false) }}@if ($p->ref), № {{ $p->ref }}@endif</span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступило {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступило</x-ui.button></form>
                                <div data-controller="sheet" class="contents">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Не поступила</x-ui.button>
                                    <x-ui.sheet id="reject-{{ $p->id }}" title="Оплата не поступила">
                                        <form method="post" action="/work/payments/{{ $p->id }}/reject" class="flex flex-col gap-3">@csrf<x-ui.field name="reason" label="Что не так" placeholder="Денег на счёте нет, платёжка не читается"/><x-ui.sheet-foot variant="secondary">Не поступила</x-ui.sheet-foot></form>
                                    </x-ui.sheet>
                                </div>
                                @if ($slip = $p->slip())<x-ui.doc :doc="\App\Support\Docs::media($slip)" class="btn btn-s btn-ghost"><x-ui.icon name="file" class="size-4"/>Платёжка</x-ui.doc>@endif
                            </div>
                        </div>
                    @endforeach
                    @if ($wantsInvoice || $moves->isNotEmpty() || $stage->template_id || $requests || $payNow->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-2">
                            {{-- Заявка поставщику пачкой: окно письма со списком файлом, отправили — все её сделки дальше. --}}
                            @if ($requests)
                                <x-mail.window-button :url="'/work/mail/new?request='.$offer->vendor_id.'&then=close'" :label="'Заявка поставщику ('.$requests.')'" class="btn-s btn-accent"/>
                            @endif
                            @if ($wantsInvoice)
                                {{-- Счета ставятся сами (`SyncDealInvoices`); гаражной не хватает нашей доли — вписать её в «Деньгах» сделки. --}}
                                @php $action = \App\Workflow\Position::gapAction($position->gap(), $deal); @endphp
                                {{-- Реквизиты Р.О.С. правятся на парковке: у кого её нет, кнопка вела бы в стену (18.10.2026). --}}
                                @if ($position->gap() === 'ros_requisites' && ! auth()->user()?->isParkAdmin())
                                    <p class="text-sm font-medium text-urgent">Ждём реквизиты Р.О.С.</p>
                                @else
                                    <a href="{{ $action['url'] }}" class="btn btn-s btn-accent" data-turbo-frame="_top">{{ $action['button'] }}</a>
                                @endif
                            @endif
                            @foreach ($payNow as $i)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" data-action="sheet#open">{{ $payNow->count() > 1 ? 'Деньги пришли, счёт '.$i->label() : 'Деньги пришли' }}</x-ui.button>
                                    <x-ui.sheet :id="'paid-'.$i->id" :title="'Оплата по счёту '.$i->label()">
                                        <x-billing.pay-form :invoice="$i" :action="'/work/money/invoices/'.$i->id.'/payments'" :sources="\App\Billing\PaymentSource::manual()" sheet/>
                                    </x-ui.sheet>
                                </div>
                            @endforeach
                            @foreach ($moves->values() as $i => $exit)
                                {{-- Письмо поставщику не ушло — главное «Написать поставщику», исходы серыми. --}}
                                @php $variant = $i === 0 && ! $wantsInvoice && $payNow->isEmpty() && $position->letterStatus() !== 'unsent' ? 'primary' : 'secondary'; @endphp
                                @if ($exit->to?->offer_state === \App\Offers\OfferState::Open && in_array($offer->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery], true))
                                    {{-- «Опубликовать» — сейчас или в слот, как кнопка редактора. --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-offer.publish-sheet :offer="$offer" id="exit-{{ $exit->id }}" action="/offers/{{ $n }}/exit/{{ $exit->id }}"/>
                                    </div>
                                @elseif ($exit->staffFields())
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                            <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="flex flex-col gap-4">
                                                @csrf
                                                {{-- Контакт и адрес из письма — уже вписаны (`Handover::prefill`), поправить можно. --}}
                                                @php $prefill = \App\Offers\Handover::prefill($exit->to, $offer); @endphp
                                                <div class="fields">
                                                    @foreach ($exit->staffFields() as $field)
                                                        <x-route.field :field="$field" :name="'fields['.$field['key'].']'" :value="$prefill[$field['key']] ?? $field['default'] ?? null"/>
                                                    @endforeach
                                                </div>
                                                <x-ui.sheet-foot>{{ $label($exit) }}</x-ui.sheet-foot>
                                            </form>
                                        </x-ui.sheet>
                                    </div>
                                @else
                                    {{-- Закрыть сделку, пока автомобиль не забрали (18.10.2026), — только переспросив. --}}
                                    @php $confirm = \App\Offers\Deal::closes($exit) && $offer->deal?->closeBlocker() ? 'Автомобиль ещё не забрали. Закрыть сделку?'
                                        : $exit->confirm; @endphp
                                    @if ($asks($exit))
                                        {{-- Спросить или «Уже согласился» — одной шторкой (`ask_consent_controller`, 28.10.2026). --}}
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" data-controller="ask-consent" data-action="submit->ask-consent#ask">
                                            @csrf<input type="hidden" name="then_take" value="" data-ask-consent-target="take"><x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                        </form>
                                    @else
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" @if ($confirm) data-turbo-confirm="{{ $confirm }}" @endif>
                                            @csrf<x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                        </form>
                                    @endif
                                @endif
                            @endforeach
                            @if ($stage->template_id)
                                {{-- Письмо не ушло — это и есть дело шага: лаймовой первой; ушло — серой «Написать ещё». --}}
                                @if ($position->letterStatus() === 'failed' && ($failedId = $position->payload['letter_failed']['id'] ?? null))
                                    {{-- Не ушло с нашей стороны — то же письмо ещё раз (`MailController::resend`). --}}
                                    <form method="post" action="/work/mail/messages/{{ $failedId }}/retry" class="order-first">@csrf<button class="btn btn-accent btn-s"><x-ui.icon name="send" class="size-4"/>Повторить отправку</button></form>
                                @elseif (in_array($position->letterStatus(), ['unsent', 'bounced'], true))
                                    {{-- Письмо — окном писем, как везде (28.10.2026: ссылка уводила на отдельную страницу); ушло — в окне его ветка, закрыли — шаг перечитан. --}}
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() === 'bounced' ? 'Написать снова' : 'Написать поставщику'" class="btn-accent btn-s order-first"/>
                                @else
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() ? 'Написать ещё' : 'Письмо вендору'" class="btn-s"/>
                                @endif
                            @endif
                            {{-- Поставщик отвечает списком (Каркаде): отметить ответ по всем его сделкам разом. --}}
                            @if ($deal && $stage->isAgreement() && $stage->waits_for === WaitsFor::Supplier && ($waiting = \App\Offers\Deal::awaitingSupplier($offer->vendor_id))->count() > 1)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Ответ по списку ({{ $waiting->count() }})</x-ui.button>
                                    <x-deal.supplier-answer :deals="$waiting" :id="'supplier-answer-'.$position->id"/>
                                </div>
                            @endif
                        </div>
                    @endif
                @else
                    <div class="step-head"><span class="step-title">{{ $blockName($block) }}</span></div>
                @endif
            </div>
        </div>
    @endforeach
    {{-- Хвост пути после маршрута: у гаражной машины — её продажа и расчёт (дорожка «Продажа», 06.10.2026). --}}
    {{ $slot }}
</div>
