{{-- Путь ветки маршрута в CRM — как таймлайн дела ТС на парковке (.steps): пройденные блоки галочкой с датой (раскрываются —
     этапы по журналу, ответы менеджера с файлами и «Вернуть на этот шаг»), текущий раскрыт — этап, чей ход и часы словами,
     просьба к менеджеру, действия: главный выход лаймовый, остальные серые, письмо вендору; срывы в тупик («Отказ
     поставщика») — в «···» с подтверждением. Впереди — серым, пока путь однозначен (Workflow\Path).
     Деньги в шаге, а не рядом: на оплате, пока счёта нет, главное действие — «Выставить счёт»; менеджер сообщил об
     оплате — его заявка тут же с «Поступило» / «Не поступила» (подтверждение само двигает путь, AdvanceOnPayment),
     и выходов «Оплата получена / не поступила» рядом нет — одно действие, а не два несвязанных. Слот — шаги после
     маршрута тем же видом (продажа гаражной машины).
     07.10.2026: поля строками (.fields), кнопка .sheet-foot.
     04.11.2026: текущий шаг — подпунктами этапов блока (`Workflow\Substeps`, вид — как у «Переоценки»), у подпункта «сейчас» —
     кто действует строкой (`x-route.who`), под ней кнопки шага.
     10.11.2026: заголовок текущего шага — сам этап («Согласие менеджера»), блок — мелкой строкой над ним; пройденное в блоке —
     строками под заголовком, ждущее в блоке — серым в конце; ждущее письмо поставщика — строкой в шаге (`x-deal.reply-line`). --}}
{{-- compact — окно машины (12.11.2026, владелец: «снизу куча навалено, менеджер несколько раз»): виден текущий шаг, пройденные
     и впереди — под строкой «Весь путь» (`x-route.fold`); менеджер сделки — словом «Менеджер»: имя и телефон — в сведениях
     шапки окна. --}}
@props(['offer', 'position', 'compact' => false])
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
    // Страховая просит реквизиты, а покупатель уже есть (10.11.2026, владелец: «это задача для нас — отправить реквизиты, что
    // менеджер прикрепил до этого»): исход к «Нет реквизитов» / «Письму с реквизитами» — «Отправить реквизиты», шаг «Нет
    // реквизитов» пройдёт сам (`AdvanceOnBuyerData`), письмо откроется (`RouteController::exit`).
    $sends = fn ($x) => $deal && $x->actor === Actor::Staff && ($x->to?->asks === \App\Workflow\Asks::Buyer || $x->to?->isRequisitesStep())
        && $deal->requisitesGap() === null;
    $label = fn ($x) => $asks($x) ? 'Спросить менеджера' : ($sends($x) ? 'Отправить реквизиты' : $x->setRelation('from', $stage)->shownLabel());
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
    // Переоценка в сделке — свой шаг «Переоценка» перед этапом маршрута (04.11.2026, владелец: «почему согласование с
    // поставщиком, если идёт переоценка — мы ещё ничего не приняли»): пока он не пройден, этап ждёт впереди без кнопок.
    $reflow = $position->track === \App\Workflow\Track::Sale && auth()->user()?->canManageCrm() ? \App\Offers\RepriceFlow::for($offer) : null;
    // Ответы — только идущей сделки (06.11.2026, владелец: Макаров «передумал» в сделке № 83, машину отдали Трофимову, а в шаге
    // его сделки № 307 висело «Менеджер ответил «Я передумал»» — начальник решил, что передумал и Трофимов). Прежние сделки —
    // в «Истории» с именем.
    $requirements = $deal ? $deal->requirements()->with(['media', 'stage'])->get() : collect();
    // Кто отвечал — по имени, и за него («(за него …)») — одним запросом на путь, а не на каждый ответ.
    $answeredBy = \App\Users\User::whereIn('id', $requirements->flatMap(fn ($r) => [$r->user_id, $r->answer['by'] ?? null])->filter()->unique())->get()->keyBy('id');
    $answerer = fn ($r) => $answeredBy->get($r->user_id)?->shortName() ?? 'Менеджер';
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
    // Черновик без цены продажи (владелец 04.10.2026): первый шаг продажи — назначить цену, а не «Выставить в продажу». Шаг не
    // из маршрута — он стоит перед ним текущим с «Оценить» (`/offers/{n}/rate`), а этап маршрута ждёт впереди серым.
    $pricing = $position->track === \App\Workflow\Track::Sale && $offer->state === \App\Offers\OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled() && auth()->user()?->canManageCrm();
@endphp
<div @class(['steps', 'steps--compact' => $compact])>
    @if ($pricing)
        <div class="step step--{{ Path::CURRENT }}">
            <span class="step-dot"></span>
            <div class="step-body">
                <div class="step-head"><span class="step-title">Назначение цены</span></div>
                <p class="step-hint text-urgent">Ждём нас</p>
                <form method="post" action="/offers/{{ $n }}/rate" class="mt-3 flex max-w-sm gap-2" data-controller="bid" data-bid-asking-value="0" data-price-guard="edit" data-price-guard-offer="{{ $n }}">
                    @csrf @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
                    <input type="hidden" name="asking_price" data-bid-target="amount" value="">
                    <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Цена продажи, ₽" aria-label="Цена продажи, ₽" data-bid-target="display" data-action="input->bid#input" data-save-into="offer-form" data-save-name="asking_price">
                    <x-ui.button size="sm" class="shrink-0">Оценить</x-ui.button>
                </form>
            </div>
        </div>
    @endif
    @foreach ($path as $step)
        {{-- Переоценка встаёт на место текущего этапа: пройденные — выше, этап — следом за ней. --}}
        @if ($reflow && $step['state'] === Path::CURRENT)<x-route.reprice-step :flow="$reflow"/>@endif
        @php
            // Пока цена не назначена или идёт переоценка, этап маршрута — впереди, без кнопок.
            if (($pricing || $reflow) && $step['state'] === Path::CURRENT) $step['state'] = Path::NEXT;
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
                                <div class="flex flex-wrap items-baseline gap-x-2 text-sm"><span>{{ $block->stages->firstWhere('id', $s['stage_id'])?->name ?? $s['stage'] }}</span><span class="nums text-ink-dim">{{ \App\Support\Ago::html($s['at'], true, words: false) }}</span></div>
                            @endforeach
                            @foreach ($answers as $r)
                                <div class="text-sm"><span class="text-ink-muted">{{ $answerer($r) }}:</span> {{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif <span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, true, words: false) }}</span></div>
                                @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $r->shown($k, $v) }}</div>@endforeach
                                @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :bytes="$f->size" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
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
                    @php
                        // Подпункты блока (`Workflow\Substeps`, 04.11.2026, владелец: «как переоценку — так и остальные этапы»):
                        // пройденные — исходом, кто и когда; сейчас — кто действует (`x-route.who`) и кнопки шага; впереди — серым
                        // с тем, чей там ход. В блоке из одного этапа подпунктов нет — шаг и есть он. Фраз «Ждём от менеджера
                        // «…»», «Менеджер ответит …» и строки с именем этапа больше нет: их говорят подпункт и строка «кто».
                        $subs = \App\Workflow\Substeps::for($position, $step['stages'], $deal);
                        $many = $subs->count() > 1;
                        // 10.11.2026 (начальник по Volvo 01527: «почему согласование с поставщиком, если ждём Макарова»): в CRM
                        // заголовок — сам этап, блок — мелкой строкой над ним с местом в блоке; блоки — язык менеджера.
                        $place = $subs->where('state', \App\Workflow\Substeps::DONE)->count() + 1;
                        $doneBy = $many ? \App\Users\User::whereIn('id', $subs->pluck('user_id')->filter()->unique())->get()->keyBy('id') : collect();
                        $whoOf = fn ($s) => match ($s?->waits_for) {
                            WaitsFor::Manager => $compact ? 'менеджер' : $deal?->buyer?->shortName(),
                            WaitsFor::Supplier => $offer->vendor?->name,
                            default => null,
                        };
                        $req = ! $wantsInvoice && $stage->awaitsManager($deal) ? $requirements->first(fn ($r) => ! $r->done_at && $r->stage_id === $stage->id) : null;
                        // Данные покупателя для счёта поставщика (03.11.2026): их вносит и админ — «Заполнить» ведёт к карточке.
                        $buyerData = $req?->asks === \App\Workflow\Asks::Buyer;
                    @endphp
                    {{-- «···» — справа от названия и в узкой колонке: длинное название переносится, а не выталкивает кнопку. --}}
                    <div class="step-head !flex-nowrap !items-start">
                        <span class="min-w-0 flex-1">
                            @if ($many && $blockName($block) !== $stage->name)<span class="step-kicker">{{ $blockName($block) }}, {{ $place }} из {{ $subs->count() }}</span>@endif
                            <span class="step-title block">{{ $stage->name }}</span>
                        </span>
                        @if ($breaks->isNotEmpty() || $undo || $extend || $forManager->isNotEmpty() || in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true))
                            {{-- «⋯» шага — общей пилюлей со шторкой действий (`x-ui.more`, 12.11.2026). --}}
                            @php $stepGroup = $undo || $extend || in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true); @endphp
                            <x-ui.more :id="$menu" class="ml-auto mt-0.5 shrink-0">
                                    @if ($stepGroup)<div class="menu-group">@endif
                                    {{-- Отмена шага, которым сюда пришли (StepBack); сразу после нажатия она же — «Отменить» в тосте. --}}
                                    @if ($undo)
                                        @php $undoText = $undo['label'] ? 'Отменить «'.$undo['label'].'»' : 'Вернуть на «'.$undo['to']->name.'»'; @endphp
                                        <x-ui.step-back close="" :action="'/offers/'.$n.'/back'" :label="$undoText" :track="$position->track->value" :text="'Вернётся «'.$undo['to']->name.'»'"/>
                                    @endif
                                    @if ($extend)
                                        @foreach ([1 => 'Продлить на день', 3 => 'Продлить на 3 дня'] as $days => $extendLabel)
                                            <form method="post" action="/offers/{{ $n }}/deadline" class="contents">@csrf<input type="hidden" name="track" value="{{ $position->track->value }}"><input type="hidden" name="days" value="{{ $days }}"><button class="menu-item w-full" role="menuitem">{{ $extendLabel }}</button></form>
                                        @endforeach
                                        <button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="deadline-{{ $position->id }}:open">Срок до даты…</button>
                                    @endif
                                    {{-- Письмо шага — без письма: сообщили сами (`SkipLetter`, 10.11.2026). --}}
                                    @if (in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true))
                                        <form method="post" action="/offers/{{ $n }}/letter-skip" class="contents" data-turbo-confirm="Поставщику сообщили не письмом?" data-turbo-confirm-text="Шаг будет ждать ответа поставщика" data-turbo-confirm-label="Сообщили сами">@csrf<button class="menu-item w-full" role="menuitem">Без письма — сообщили сами</button></form>
                                    @endif
                                    @if ($stepGroup)</div>@endif
                                    @if ($forManager->isNotEmpty())<div class="menu-group">@endif
                                    @foreach ($forManager as $exit)
                                        {{-- Уход в продажу («Я передумал») отменяет сделку — переспрашиваем; остальное — сразу. --}}
                                        @php $retreat = $exit->isRetreat(); @endphp
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" @if ($retreat) data-turbo-confirm="За менеджера: «{{ $exit->label }}»?" data-turbo-confirm-text="Сделка отменится, ТС вернётся в продажу" data-turbo-confirm-label="Отменить сделку" @endif>
                                            @csrf<input type="hidden" name="for" value="manager">
                                            <button class="menu-item w-full {{ $retreat ? 'text-danger' : '' }}" role="menuitem"><x-ui.icon name="user" class="size-4"/>За менеджера: «{{ $exit->label }}»</button>
                                        </form>
                                    @endforeach
                                    @if ($forManager->isNotEmpty())</div>@endif
                                    @if ($breaks->isNotEmpty())<div class="menu-group">@endif
                                    @foreach ($breaks as $exit)
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="contents" data-turbo-confirm="{{ $exit->confirm ?: $exit->label.'?' }}">@csrf<button class="menu-item w-full text-danger" role="menuitem">{{ $label($exit) }}</button></form>
                                    @endforeach
                                    @if ($breaks->isNotEmpty())</div>@endif
                            </x-ui.more>
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
                    @if ($subs->where('state', \App\Workflow\Substeps::DONE)->isNotEmpty())
                        <ol class="substeps substeps--done">
                            @foreach ($subs->where('state', \App\Workflow\Substeps::DONE) as $sub)
                                <li class="substep substep--done">
                                    <span class="substep-dot"><x-ui.icon name="check" class="size-2.5"/></span>
                                    <div class="substep-body"><div class="substep-head"><span class="substep-title">{{ $sub['name'] }}</span>@if ($sub['at'])<span class="substep-meta">@if ($by = $doneBy->get($sub['user_id'])){{ $by->shortName() }}, @endif<span class="nums">{{ \App\Support\Ago::html($sub['at'], true, words: false) }}</span></span>@endif</div></div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <x-route.who :position="$position" :offer="$offer" :deal="$deal" :named="! $compact"/>
                    {{-- Письмо поставщика ждёт разбора — строкой в шаге, а не лаймовой плашкой над путём (10.11.2026). --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canManageCrm())<x-deal.reply-review :deal="$deal" line/>@endif
                    {{-- Покупателя менеджер должен дать с согласия (10.11.2026, `AskBuyer`): пока не дал — строкой дела, внести за него. --}}
                    @if ($deal?->buyer_asked_at && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canManageCrm())
                        <x-route.task-row icon="user" :href="'/offers/'.$offer->number.($deal->hasContract() ? '?dkp=1#dkp' : '?buyer=1#invoice-buyer')" data-turbo-frame="_top">Менеджер не указал покупателя</x-route.task-row>
                    @endif
                    {{-- День и время подписания (`Signing`, 11.11.2026): пока поставщик готовит договор — что назвал менеджер, или
                         выбрать за него. --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && $deal->signing_at === null && auth()->user()?->canManageCrm()
                        && \App\Offers\Signing::asksTime($position->stage->loadMissing('exits.to.exits')))
                        @if (\App\Offers\Signing::wish($deal))
                            <x-deal.signing-when :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing-wish'" class="mt-2"/>
                        @else
                            <p class="mt-3 text-sm text-ink-muted">Менеджер ещё не назвал время подписания</p>
                            <details class="signing-edit mt-2">
                                <summary class="step-acts"><span class="btn btn-s btn-secondary"><span class="signing-edit-open">Время за менеджера</span><span class="signing-edit-close">Свернуть</span></span></summary>
                                <x-deal.signing-slots :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing-wish'" submit="Сохранить" class="mt-3"/>
                            </details>
                        @endif
                    @endif
                    {{-- Запись на подписание (`Offers\Signing`): менеджер выбирает время — его варианты плитками (за него), выбрано —
                         карточка встречи. --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && \App\Offers\Signing::picks($position->stage) && auth()->user()?->canManageCrm())
                        <p class="mt-3 text-sm text-ink-muted">Выбрать за менеджера</p>
                        @if ($deal->signing_slots)
                            <x-deal.signing-pick :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing'" :other="'/work/deals/'.$deal->id.'/signing-wish'" class="mt-2"/>
                        @else
                            <x-deal.signing-when :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing-wish'" picks class="mt-2"/>
                        @endif
                    @elseif ($deal && $position->track === \App\Workflow\Track::Sale && ! $deal->signing_at && \App\Offers\Signing::awaitsSlots($position->stage))
                        <x-deal.signing-when :deal="$deal" readonly class="mt-3"/>
                    @elseif ($deal && $position->track === \App\Workflow\Track::Sale && \App\Offers\Signing::booked($deal))
                        <x-deal.appointment :deal="$deal" :confirmed="$position->waitsFor() === \App\Workflow\WaitsFor::Manager" :ics="'/work/deals/'.$deal->id.'/signing.ics'" class="mt-3"/>
                    @endif
                    {{-- Контакты филиала (`vendors.signing_office`, 11.11.2026) — кому звонить про запись, только в CRM. --}}
                    @php $officeContacts = $deal && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canManageCrm()
                        && (\App\Offers\Signing::awaitsSlots($position->stage) || \App\Offers\Signing::picks($position->stage) || (\App\Offers\Signing::booked($deal) && $position->waitsFor() !== \App\Workflow\WaitsFor::Manager))
                        ? \App\Offers\Signing::office($deal)['contacts'] : []; @endphp
                    @if ($officeContacts)
                        <x-ui.info class="mt-3">
                            @foreach ($officeContacts as $c)
                                <x-ui.info-row icon="user">{{ trim($c['name'].($c['ext'] ? ', вн. '.$c['ext'] : '').($c['phone'] ? ', '.$c['phone'] : ''), ', ') }}</x-ui.info-row>
                            @endforeach
                        </x-ui.info>
                    @endif
                    @if ($buyerData && auth()->user()?->canManageCrm())
                        <div class="step-acts mt-3"><a href="/offers/{{ $offer->number }}?buyer=1#invoice-buyer" class="btn btn-s btn-accent !h-auto min-h-9 whitespace-normal py-1.5" data-turbo-frame="_top">Заполнить за менеджера</a></div>
                    @endif
                    {{-- Согласие менеджера: чаще всего он уже сказал «да» устно — «Беру в работу» за него видной тихой кнопкой. --}}
                    @if ($takeFor)
                        <form method="post" action="/offers/{{ $n }}/exit/{{ $takeFor->id }}" class="step-acts mt-2">
                            @csrf<input type="hidden" name="for" value="manager">
                            {{-- В узкой дорожке подпись переносится, а не вылезает за карточку. --}}
                            <x-ui.button size="sm" variant="secondary" class="!h-auto min-h-9 w-full whitespace-normal py-1.5">«{{ $takeFor->label }}» за менеджера</x-ui.button>
                        </form>
                    @endif
                    {{-- Менеджер платит поставщику по его счёту, а счёта не видит (10.11.2026, 0790/046/11079/26) — красной строкой. --}}
                    @if ($deal && \App\Offers\SupplierInvoice::onPay($deal, $stage) && auth()->user()?->canManageCrm() && \App\Offers\SupplierInvoice::shown($offer)->isEmpty())
                        <div class="contents" data-controller="sheet">
                            <x-route.task-row icon="file" urgent aria-controls="invoice-pay-{{ $position->id }}">Менеджер не видит счёт поставщика</x-route.task-row>
                            <x-deal.supplier-invoice-sheet :id="'invoice-pay-'.$position->id" :deal="$deal"/>
                        </div>
                    @endif
                    {{-- Менеджер уже приложил, но шаг ещё не отметил — файл видно сразу. --}}
                    @if ($req && $req->getMedia('files')->isNotEmpty())
                        <div class="mt-2 flex flex-col gap-1">@foreach ($req->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :bytes="$f->size" href="/files/{{ $f->id }}" class="py-1"/>@endforeach</div>
                    @endif
                    {{-- Документ об оплате (10.11.2026): на шаге оплаты админ прикладывает за менеджера (чек прислал в чат), дальше — одним
                         PDF для страховой (`PaymentProofPdf`), пока письмо об оплате не ушло. --}}
                    @php $payProof = $deal && auth()->user()?->canManageCrm() ? \App\Offers\Documents\PaymentProofPdf::files($deal)->isNotEmpty() : false; @endphp
                    @if ($req && $req->asks === \App\Workflow\Asks::Document && $stage->isPayStep() && auth()->user()?->canManageCrm())
                        <div class="step-acts mt-2" data-controller="sheet">
                            <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">{{ $req->getMedia('files')->isEmpty() ? 'Приложить за менеджера' : 'Документ об оплате' }}</x-ui.button>
                            <x-deal.payment-proof-sheet :id="'payment-proof-'.$position->id" :deal="$deal" :requirement="$req"/>
                        </div>
                    @endif
                    @if ($payProof && ($stage->isPayStep() || ($stage->waits_for === WaitsFor::Supplier && $stage->template_id && $position->letterStatus() !== 'sent')))
                        <a href="/work/deals/{{ $deal->id }}/payment-proof.pdf" target="_blank" class="mt-2 flex items-center gap-2 text-sm text-accent-text"><x-ui.file-icon name="Оплата.pdf" class="file-icon-s"/>Документ об оплате, PDF</a>
                    @endif
                    {{-- ДКП: что вписываем мы (продавец по СТС, документы ТС, цена) — прямо в шаге, «Заполнить» открывает карточку «ДКП»
                         сразу полями (17.10.2026, владелец: «нет возможности указать данные продавца, всё встало» — поля жили за
                         «Изменить» внизу дорожки «Сделка»). Покупателя вносит менеджер — его тут нет. --}}
                    @if ($deal?->isActive() && $deal->hasContract() && auth()->user()?->canManageCrm()
                        && ($ours = array_values(array_diff(\App\Offers\DealContract::for($deal)->missing(), ['покупатель', 'данные покупателя', 'скан паспорта покупателя']))))
                        <x-route.task-row icon="file" urgent :href="'/offers/'.$offer->number.'?dkp=1#dkp'" data-turbo-frame="_top">ДКП не готов: {{ implode(', ', $ours) }}</x-route.task-row>
                    @endif
                    @if ($position->fields())
                        <div class="mt-2 text-sm">@foreach ($position->fields() as $k => $v)<div><span class="text-ink-muted">{{ collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $v }}</div>@endforeach</div>
                    @endif
                    @php $doneNames = $subs->where('state', \App\Workflow\Substeps::DONE)->pluck('name')->map(fn ($v) => mb_strtolower($v)); @endphp
                    @foreach ($answers as $r)
                        {{-- Ответ, что уже стоит пройденным подпунктом с именем и временем, второй строкой не повторяем (10.11.2026). --}}
                        @unless ($doneNames->contains(mb_strtolower($r->answer['label'] ?? $r->answer['exit'] ?? '')))
                        <div class="mt-2 text-sm"><span class="text-ink-muted">{{ $answerer($r) }}:</span> «{{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}»@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif @if ($r->done_at)<span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, true, words: false) }}</span>@endif</div>
                        @endunless
                        @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $r->shown($k, $v) }}</div>@endforeach
                        @foreach ($r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :bytes="$f->size" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
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
                        {{-- `.step-acts`: кнопки делят ширину дорожки, перенеслись — каждая во всю ширину (05.11.2026, владелец про
                             три узкие дорожки редактора: «вёрстка — треш»; кнопки разной длины стояли лесенкой). --}}
                        <div class="step-acts mt-3">
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
                            {{-- Ответ поставщика на запись (13.11.2026, `SigningReply`): «записали», «есть на 13.11 11, 12, 13», «не записан» —
                                 строкой над кнопками; шторки «Запись подтверждена» / «Поставщик предложил другое время» — уже заполнены. --}}
                            @php $signingReply = $deal && \App\Offers\Signing::confirmable($position->stage) ? \App\Offers\SigningReply::of($deal) : null; @endphp
                            @if ($signingReply)
                                <p @class(['signing-reply w-full text-sm', 'text-urgent' => $signingReply->kind === 'none'])>{{ $signingReply->line() }} <span class="text-ink-muted">письмо {{ \App\Support\Ago::at($signingReply->message->date_at) }}</span></p>
                            @endif
                            @foreach ($moves->values() as $i => $exit)
                                {{-- Письмо поставщику не ушло — главное «Написать поставщику», исходы серыми. --}}
                                @php $variant = $i === 0 && ! $wantsInvoice && $payNow->isEmpty() && $position->letterStatus() !== 'unsent' ? 'primary' : 'secondary'; @endphp
                                @if ($exit->to?->offer_state === \App\Offers\OfferState::Open && in_array($offer->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery], true))
                                    {{-- «Выставить в продажу» — сейчас или в слот, как кнопка редактора. --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-offer.publish-sheet :offer="$offer" id="exit-{{ $exit->id }}" action="/offers/{{ $n }}/exit/{{ $exit->id }}"/>
                                    </div>
                                @elseif ($deal && \App\Offers\Signing::confirmExit($exit))
                                    {{-- «Запись подтверждена» — на какое из названных менеджером времён записал поставщик (`ConfirmSigning`). --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="confirm-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.signing-confirm-sheet :id="'confirm-'.$exit->id" :deal="$deal" :exit="$exit" :reply="$signingReply"/>
                                    </div>
                                @elseif ($deal && \App\Offers\Signing::slotsExit($exit))
                                    {{-- Свободное время поставщика — шторкой: адрес и время, менеджер выберет плиткой (`Offers\Signing`). --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="slots-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.signing-slots-sheet :id="'slots-'.$exit->id" :deal="$deal" :exit="$exit" :reply="$signingReply"/>
                                    </div>
                                @elseif (\App\Offers\SupplierInvoice::needs($deal, $exit))
                                    {{-- На оплату поставщику менеджером — только со счётом: шторка выбора счёта (`SupplierInvoice`). --}}
                                    <div data-controller="sheet">
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="invoice-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.supplier-invoice-sheet :id="'invoice-'.$exit->id" :deal="$deal" :exit="$exit"/>
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
                                        @php $next = $exit->consequence(); @endphp
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" @if ($confirm) data-turbo-confirm="{{ $confirm }}" @if ($next) data-turbo-confirm-text="Дальше: {{ $next }}" @endif @elseif ($next) data-turbo-confirm="{{ $label($exit) }}?" data-turbo-confirm-text="Дальше: {{ $next }}" data-turbo-confirm-label="{{ $label($exit) }}" @endif>
                                            @csrf<x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                        </form>
                                    @endif
                                @endif
                            @endforeach
                            @php
                                // Письмо с реквизитами без реквизитов — не кнопкой, а чего не хватает (03.11.2026, владелец: «почему
                                // „Написать поставщику“, если покупатель так и не заполнен»).
                                $requisitesGap = $stage->isRequisitesStep() && $deal ? $deal->requisitesGap() : null;
                            @endphp
                            @if ($requisitesGap)
                                <p class="order-first w-full text-sm font-medium text-urgent">{{ $requisitesGap }} — письмо поставщику пока не написать @if (auth()->user()?->canManageCrm())<a href="{{ $deal->schemeOf()->paysCar() || $deal->isGarageUs() ? \App\Support\Surface::Park->url('/money/parties') : '/offers/'.$offer->number.'?buyer=1#invoice-buyer' }}" class="btn btn-s btn-quiet ml-1" data-turbo-frame="_top">Заполнить</a>@endif</p>
                            @elseif ($stage->template_id)
                                {{-- Письмо не ушло — это и есть дело шага: лаймовой первой; ушло — серой «Написать ещё». --}}
                                @if ($position->letterStatus() === 'failed' && ($failedId = $position->payload['letter_failed']['id'] ?? null))
                                    {{-- Не ушло с нашей стороны — то же письмо ещё раз (`MailController::resend`). --}}
                                    <form method="post" action="/work/mail/messages/{{ $failedId }}/retry" class="order-first">@csrf<button class="btn btn-accent btn-s"><x-ui.icon name="send" class="size-4"/>Повторить отправку</button></form>
                                @elseif (in_array($position->letterStatus(), ['unsent', 'bounced'], true))
                                    {{-- Письмо — окном писем, как везде (28.10.2026: ссылка уводила на отдельную страницу); ушло — в окне его ветка, закрыли — шаг перечитан. --}}
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() === 'bounced' ? 'Написать снова' : ($deal && str_contains((string) $stage->template?->body, 'Документ об оплате') ? 'Отправить страховой' : 'Написать поставщику')" class="btn-accent btn-s order-first"/>
                                @else
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() ? 'Написать ещё' : 'Письмо вендору'" class="btn-s"/>
                                @endif
                            @endif
                            {{-- Поставщик отвечает списком (Каркаде, `DealFormat::Wholesale`): отметить ответ по всем его сделкам разом. У Альфы
                                 (03.11.2026) кнопка «Ответ по списку (85)» стояла зря — она отвечает по каждой машине. --}}
                            @if ($deal && $offer->vendor?->deal_format === \App\Vendors\DealFormat::Wholesale && $stage->isAgreement() && $stage->waits_for === WaitsFor::Supplier && ($waiting = \App\Offers\Deal::awaitingSupplier($offer->vendor_id))->count() > 1)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Ответ по списку ({{ $waiting->count() }})</x-ui.button>
                                    <x-deal.supplier-answer :deals="$waiting" :id="'supplier-answer-'.$position->id"/>
                                </div>
                            @endif
                        </div>
                        {{-- Что будет после кнопки (03.11.2026, «сотрудникам страшно нажимать») — в переспросе кнопки, а не строкой «Дальше:»
                             под ней (10.11.2026, владелец: «месиво»). --}}
                    @endif
                    {{-- Ход менеджера, а у нас кнопок нет (14.11.2026, владелец по 2611043624 «Связаться с владельцем»: «непонятно, что от
                         нас требуется, и не можем за него прокликать» — ответ за него жил только в «···» шага): его ответы — тихими
                         кнопками под шагом, та же форма, что пункт «···» (`RouteController::forManager`). Уход из сделки — только в «···». --}}
                    {{-- Данные покупателя — своей кнопкой «Заполнить за менеджера»; без них ответ за него можно, только если договор
                         уже подписан (`Deal::contractSigned`). --}}
                    @php $forKeys = $stage->asks === \App\Workflow\Asks::Buyer && ! $deal?->contractSigned() ? collect() : $forManager->reject(fn ($x) => $x->isRetreat()); @endphp
                    @if ($forKeys->isNotEmpty() && ! $takeFor && ! ($wantsInvoice || $moves->isNotEmpty() || $stage->template_id || $requests || $payNow->isNotEmpty()))
                        <div class="step-acts mt-3">
                            @foreach ($forKeys as $exit)
                                <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}">@csrf<input type="hidden" name="for" value="manager"><button class="btn btn-s btn-quiet w-full">За менеджера: {{ $exit->label }}</button></form>
                            @endforeach
                        </div>
                    @endif
                    @if ($subs->where('state', \App\Workflow\Substeps::WAIT)->isNotEmpty())
                        {{-- Будущие подпункты — в свёрнутом пути окна их нет (12.11.2026): видны, когда раскрыли «Весь путь». --}}
                        <ol class="substeps substeps--wait">
                            @foreach ($subs->where('state', \App\Workflow\Substeps::WAIT) as $sub)
                                <li class="substep substep--wait">
                                    <span class="substep-dot"></span>
                                    <div class="substep-body"><div class="substep-head"><span class="substep-title">{{ $sub['name'] }}</span>@if ($who = $whoOf($sub['stage']))<span class="substep-meta">{{ $who }}</span>@endif</div></div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                @else
                    <div class="step-head"><span class="step-title">{{ $blockName($block) }}</span></div>
                @endif
            </div>
        </div>
    @endforeach
    {{-- Хвост пути после маршрута: у гаражной машины — её продажа и расчёт (дорожка «Продажа», 06.10.2026). --}}
    {{ $slot }}
    @if ($compact)
        @php $stepsCount = count($path) + ($pricing ? 1 : 0) + substr_count((string) $slot, 'step step--'); @endphp
        <x-route.fold :count="$stepsCount"/>
    @endif
</div>
