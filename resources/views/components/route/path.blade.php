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
{{-- compact — окно машины: менеджер сделки — словом «Менеджер» (имя и телефон — в сведениях шапки окна). Путь — целиком: свёрнутый
     до текущего шага с «Весь путь» (12.11.2026) не прижился (16.11.2026, владелец: «непонятно, на каком этапе»). --}}
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
    // «Поставщик просит реквизиты» — всегда этими словами (18.11.2026, владелец: «кнопка „Отправить реквизиты“ вводит в
    // заблуждение»): отправка — уже в шаге «Письмо с реквизитами», там и покупатель.
    $label = fn ($x) => $asks($x) ? 'Спросить менеджера' : $x->setRelation('from', $stage)->shownLabel();
    $exits = $stage->exitsFor(Actor::Staff, $deal)->reject(fn ($x) => $x->acceptsBid());
    // «За менеджера» (28.10.2026, владелец: «он мне уже устно подтвердил»): его ответы шага — пунктами «···», нажимает
    // админ (`RouteController::forManager`, в «Истории» — «за менеджера»).
    $forManager = $deal?->buyer_id && auth()->user()?->canEditDeals() ? $stage->exitsFor(Actor::Manager, $deal) : collect();
    $takeFor = $stage->asksConsent() ? $forManager->first(fn ($x) => $x->takes()) : null;
    // Имена блоков как есть: кто вывозит, видно строкой над путём («Стоит у Сергей К.» имя не склоняет).
    $blockName = fn ($block) => $block->name;
    [$breaks, $moves] = $exits->partition(fn ($x) => $x->to?->block && $x->to->block_id !== $stage->block_id && $x->to->block->isDeadEnd())->all();
    // Модератор ведёт сделку, но решает админ (17.11.2026): ни выходов в тупик («Отказ поставщика»), ни закрытия сделки, ни выставления
    // в продажу (`RouteController::exit` их не примет).
    if (! auth()->user()?->canManageCrm()) {
        $breaks = collect();
        $moves = $moves->reject(fn ($x) => \App\Offers\Deal::closes($x) || $x->to?->offer_state === \App\Offers\OfferState::Open)->values();
    }
    // Исход сотрудника «… за менеджера» (Альфа: «Договор подписан за менеджера») — тот же ответ, что «За менеджера: «Договор
    // подписан»» в «⋯» шага: второй раз не даём (19.11.2026, владелец: «не должно быть двух плашек действий»).
    $forManager = $forManager->reject(fn ($x) => $moves->contains(fn ($m) => mb_strtolower($m->label) === mb_strtolower($x->label).' за менеджера'))->values();
    // «Заявка отправлена» (Каркаде) — не кнопка: её жмёт само письмо заявки по всем сделкам вендора разом.
    $requestExit = $deal ? $moves->first(fn ($x) => mb_strtolower($x->label) === 'заявка отправлена') : null;
    $requests = $requestExit ? \App\Offers\Deal::awaitingRequest($offer->vendor_id)->count() : 0;
    if ($requestExit) {
        $moves = $moves->reject(fn ($x) => $x->is($requestExit));
    }
    // Переоценка в сделке — свой шаг «Переоценка» перед этапом маршрута (04.11.2026, владелец: «почему согласование с
    // поставщиком, если идёт переоценка — мы ещё ничего не приняли»): пока он не пройден, этап ждёт впереди без кнопок.
    $reflow = $position->track === \App\Workflow\Track::Sale && auth()->user()?->canManageCrm() ? \App\Offers\RepriceFlow::for($offer) : null;
    // Доп. просьба поставщику держит ход сделки (18.11.2026, `Offers\ExtraAsk`): свой шаг на месте этапа, этап ждёт следом без кнопок.
    $extra = $position->track === \App\Workflow\Track::Sale && auth()->user()?->canEditDeals() ? \App\Offers\ExtraAsk::holding($deal) : null;
    // Ответы — только идущей сделки (06.11.2026, владелец: Макаров «передумал» в сделке № 83, машину отдали Трофимову, а в шаге
    // его сделки № 307 висело «Менеджер ответил «Я передумал»» — начальник решил, что передумал и Трофимов). Прежние сделки —
    // в «Истории» с именем.
    $requirements = $deal ? $deal->requirements()->with(['media', 'stage'])->get() : collect();
    // Кто отвечал — по имени, и за него («(за него …)») — одним запросом на путь, а не на каждый ответ.
    $answeredBy = \App\Users\User::whereIn('id', $requirements->flatMap(fn ($r) => [$r->user_id, $r->answer['by'] ?? null])->filter()->unique())->get()->keyBy('id');
    $answerer = fn ($r) => $answeredBy->get($r->user_id)?->shortName() ?? 'Менеджер';
    $menu = 'route-more-'.$position->id;
    $invoices = $deal ? $deal->shownInvoices()->reject(fn ($i) => $i->isOwed() || $i->state === InvoiceState::Void) : collect();
    // Этап оплаты без счёта — ход наш (`Position::awaitsInvoice`): «Выставить счёт» первой кнопкой.
    $wantsInvoice = $deal && $position->awaitsInvoice();
    $paidExit = fn ($x) => preg_match('/^оплата (получена|не поступила)/u', mb_strtolower($x->label)) === 1;
    // «Поступило / Не поступила» по сообщению менеджера — только где оплату проверяем мы. На «Подтверждении оплаты» страховой
    // (ждём поставщика) — нет (16.11.2026, владелец: «мы этого не знаем: сначала платёжку отправляем в Альфу, они отвечают,
    // что оплачено»): там «Отправить страховой», после письма — её ответ кнопками шага.
    $claims = $moves->contains($paidExit) && $stage->waits_for !== WaitsFor::Supplier && auth()->user()?->canManageCrm() ?$invoices->flatMap(fn ($i) => $i->claims->map(fn ($p) => [$i, $p])) : collect();
    // «Оплата получена / не поступила» — деньги, решает админ (17.11.2026): модератору этих кнопок нет.
    if ($claims->isNotEmpty() || ! auth()->user()?->canManageCrm()) {
        $moves = $moves->reject($paidExit);
    }
    // «Деньги пришли» в шаге больше нет (16.11.2026, владелец: «непонятно, зачем кнопка, что она делает»; на «Оплате счёта»
    // страховой она отмечала наш счёт подбора, а шаг — про оплату поставщику): отметка нашей оплаты — строкой «Деньги пришли?»
    // под счётом в блоке «ООО «ПРАЙМ»» «Продажи» (`x-deal.money`); оплата по-прежнему сама проходит шаг (`AdvanceOnPayment`).
    $payNow = collect();
    $undo = \App\Workflow\Actions\StepBack::undoable($offer, $position->track);
    // «Продлить срок» (19.01.2027, начальник): шагу с часами — +1 день, +3 дня, до даты (`ExtendDeadline`).
    $extend = $position->deadline_at !== null && auth()->user()?->canEditDeals();
    // Черновик без цены продажи (владелец 04.10.2026): первый шаг продажи — назначить цену, а не «Выставить в продажу». Шаг не
    // из маршрута — он стоит перед ним текущим с «Оценить» (`/offers/{n}/rate`), а этап маршрута ждёт впереди серым.
    $pricing = $position->track === \App\Workflow\Track::Sale && $offer->state === \App\Offers\OfferState::Draft && ! $offer->asking_price && ! $offer->isScheduled() && auth()->user()?->canManageCrm();
@endphp
<div class="steps">
    {{-- «Заявка поступила» (18.11.2026, владелец: «перед „Приём подтверждений“ не хватает»): дата — первое событие «Истории»
         машины, какой бы источник ни был (письмо, Мигторг, рукой). --}}
    @if ($received = $offer->events()->reorder()->min('created_at') ?? $offer->created_at)
        <div class="step step--{{ Path::DONE }}">
            <span class="step-dot"><x-ui.icon name="check" class="size-3"/></span>
            <div class="step-body">
                <div class="step-head" style="padding-inline-end: 1.5rem"><span class="step-title flex-1">Заявка поступила</span><span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html(\Illuminate\Support\Carbon::parse($received), words: false) }}</span></div>
            </div>
        </div>
    @endif
    @if ($pricing)
        <div class="step step--{{ Path::CURRENT }}">
            <span class="step-dot"></span>
            <div class="step-body">
                <div class="step-head"><span class="step-title">Назначение цены</span></div>
                <p class="step-hint text-ink-muted">Ждём нас</p>
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
        @if ($extra && $step['state'] === Path::CURRENT)<x-route.extra-step :ask="$extra" :deal="$deal"/>@endif
        @php
            // Пока цена не назначена, идёт переоценка или доп. просьба держит сделку, этап маршрута — впереди, без кнопок.
            if (($pricing || $reflow || $extra) && $step['state'] === Path::CURRENT) $step['state'] = Path::NEXT;
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
                            {{-- Стрелка — прямым ребёнком: `.step-details > summary > .step-chevron` ставит её к правому краю, дата — перед ней
                                 (19.11.2026, владелец: в обёртке она стояла на 1,5rem левее края, где «···» текущего шага). --}}
                            @if ($step['at'])<span class="nums shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($step['at'], words: false) }}</span>@endif
                            <x-ui.icon name="chevron-down" class="step-chevron size-4 text-ink-dim"/>
                        </summary>
                        <div class="mt-1 flex flex-col gap-1">
                            @foreach ($step['stages'] as $s)
                                <div class="flex flex-wrap items-baseline gap-x-2 text-sm"><span>{{ $block->stages->firstWhere('id', $s['stage_id'])?->name ?? $s['stage'] }}</span><span class="nums text-ink-dim">{{ \App\Support\Ago::html($s['at'], true, words: false) }}</span></div>
                            @endforeach
                            @foreach ($answers as $r)
                                <div class="text-sm"><span class="text-ink-muted">{{ $answerer($r) }}:</span> {{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif <span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, true, words: false) }}</span></div>
                                @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $r->shown($k, $v) }}</div>@endforeach
                                {{-- Платёжка шага оплаты и подписанный договор — файлом в «Продаже» (одним PDF), в ходе сделки второй раз не показываем
                             (16.11.2026, договор — 17.11.2026). --}}
                                @foreach ((in_array($r->answer['exit'] ?? null, \App\Offers\Documents\PaymentProofPdf::EXITS, true) || ($r->asks === \App\Workflow\Asks::Document && $r->stage?->aboutContract())) ? [] : $r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :bytes="$f->size" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
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
                        // заголовок — сам этап, блок — мелкой строкой над ним; блоки — язык менеджера.
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
                            {{-- Блок над этапом — словом, без «1 из 2» (16.11.2026, владелец: «достаточно просто „Оплата“»). --}}
                            @if ($many && $blockName($block) !== $stage->name)<span class="step-kicker">{{ $blockName($block) }}</span>@endif
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
                    {{-- Ход менеджера (14.11.2026, владелец по 2611043624 «Связаться с владельцем»: «непонятно, что от нас требуется, и не можем
                         за него прокликать»): его ответы — кнопками за него, когда у нас своих нет; уход из сделки — только в «···». Данные
                         покупателя — своей кнопкой; без них ответ за него можно, только если договор уже подписан (`Deal::contractSigned`). --}}
                    @php
                        $forKeys = $stage->asks === \App\Workflow\Asks::Buyer && ! $deal?->contractSigned() ? collect() : $forManager->reject(fn ($x) => $x->isRetreat());
                        $forKeys = ! $takeFor && ! ($wantsInvoice || $moves->isNotEmpty() || $stage->template_id || $requests || $payNow->isNotEmpty()) ? $forKeys : collect();
                        $proofFor = $req && $req->asks === \App\Workflow\Asks::Document && $stage->isPayStep() && auth()->user()?->canEditDeals();
                        // Шаг платит только комиссию без счёта (18.11.2026) — ссылкой или наличными, платёжки нет: «Приложить платёжку за
                        // менеджера» не нужна. Где платят поставщику или по счёту — как было.
                        // Шаг пройдёт сам, когда деньги придут (`AdvanceOnPayment`): «За менеджера: Оплатил, документ приложен» тоже не нужен.
                        $feeOnly = false;
                        if ($proofFor && $deal && ! $deal->paysSupplierOn($stage)) {
                            $ours = $invoices->filter(fn ($i) => $i->direction === 'issued' && $i->state !== \App\Billing\InvoiceState::Void);
                            $feeOnly = $ours->isNotEmpty() && ! $ours->contains(fn ($i) => $i->isDocument());
                            $proofFor = ! $feeOnly;
                        }
                        $reqFiles = $req ? $req->getMedia('files') : collect();
                        // Оплату с документом проходят только с платёжкой (16.11.2026, владелец: «без этого следующий шаг не продвинется»):
                        // «Приложить платёжку за менеджера» и есть этот ход, голого «За менеджера: Оплатил, документ приложен» нет.
                        $forKeys = $proofFor || $feeOnly ? $forKeys->reject(fn ($x) => in_array($x->label, \App\Offers\Documents\PaymentProofPdf::EXITS, true)) : $forKeys;
                    @endphp
                    {{-- Всё, что за менеджера, — под строкой «Менеджер» (16.11.2026, владелец: «„Беру в работу за менеджера“ должно быть под
                         „Менеджер“, а так они разорваны»): что он уже приложил, данные покупателя, документ об оплате, его ответы. --}}
                    {{-- Письмо ждёт разбора — оно строкой «Письмо от … / Показать менеджеру?» ниже (`x-deal.reply-review`); «Поставщик ответил»
                         о том же письме второй строкой не пишем (17.11.2026). --}}
                    @php $letterWaits = $deal?->buyer_id && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canEditDeals()
                        && \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->exists(); @endphp
                    <x-route.who :position="$position" :offer="$offer" :deal="$deal" :named="! $compact" :replied="! $letterWaits">
                        <x-slot:acts>
                            @if ($reqFiles->isNotEmpty())
                                {{-- Строкой дела, как остальное в шаге (16.11.2026): имя файла с телефона — набор цифр, важно, что он есть. --}}
                                <div class="w-full">
                                    @foreach ($reqFiles as $f)
                                        <x-route.task-row icon="file" :href="'/files/'.$f->id" target="_blank" class="!mt-0">
                                            Менеджер приложил файл
                                            <x-slot:sub>{{ mb_strtoupper(pathinfo($f->file_name, PATHINFO_EXTENSION)) }}, {{ \App\Support\FileSize::short($f->size) }}</x-slot:sub>
                                        </x-route.task-row>
                                    @endforeach
                                </div>
                            @endif
                            @if ($buyerData && auth()->user()?->canEditDeals())
                                <a href="/offers/{{ $offer->number }}?buyer=1#invoice-buyer" class="btn btn-s btn-quiet" data-turbo-frame="_top">Заполнить реквизиты за менеджера</a>
                            @endif
                            @if ($proofFor)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">{{ $reqFiles->isEmpty() ? 'Приложить платёжку за менеджера' : 'Документ об оплате' }}</x-ui.button>
                                    <x-deal.payment-proof-sheet :id="'payment-proof-'.$position->id" :deal="$deal" :requirement="$req"/>
                                </div>
                            @endif
                            @if ($takeFor)
                                <form method="post" action="/offers/{{ $n }}/exit/{{ $takeFor->id }}">
                                    @csrf<input type="hidden" name="for" value="manager">
                                    <x-ui.button size="sm" variant="secondary">«{{ $takeFor->label }}» за менеджера</x-ui.button>
                                </form>
                            @endif
                            @foreach ($forKeys as $exit)
                                <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}">@csrf<input type="hidden" name="for" value="manager"><x-ui.button size="sm" variant="secondary">За менеджера: {{ $exit->label }}</x-ui.button></form>
                            @endforeach
                        </x-slot:acts>
                    </x-route.who>
                    {{-- Покупатель и чего не хватает договору — строками «Продажи» (`x-deal.buyer`, `x-deal.contract-gaps`), не делами шага
                         (16.11.2026, владелец: «как будто мы эти данные от менеджера ждём»). --}}
                    {{-- Машину забирает менеджер, а контакт владельца ему не показан — строкой дела с шторкой «Показать менеджеру» (15.11.2026). --}}
                    @if ($deal?->isActive() && $position->track === \App\Workflow\Track::Sale && $deal->buyerPicksUp())
                        <x-deal.contact-shown :deal="$deal" as="task"/>
                    @endif
                    {{-- День и время подписания (`Signing`, 11.11.2026): пока поставщик готовит договор — что назвал менеджер, или
                         выбрать за него. --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && $deal->signing_at === null && auth()->user()?->canEditDeals()
                        && \App\Offers\Signing::asksTime($position->stage->loadMissing('exits.to.exits')))
                        @if (\App\Offers\Signing::wish($deal))
                            <x-deal.signing-when :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing-wish'" class="mt-2"/>
                        @else
                            <p class="mt-3 text-sm text-ink-muted">Менеджер ещё не назвал время подписания</p>
                            <details class="signing-edit mt-2">
                                <summary class="step-acts"><span class="btn btn-s btn-quiet"><span class="signing-edit-open">Время за менеджера</span><span class="signing-edit-close">Свернуть</span></span></summary>
                                <x-deal.signing-slots :deal="$deal" :action="'/work/deals/'.$deal->id.'/signing-wish'" submit="Сохранить" class="mt-3"/>
                            </details>
                        @endif
                    @endif
                    {{-- Запись на подписание (`Offers\Signing`): менеджер выбирает время — его варианты плитками (за него), выбрано —
                         карточка встречи. --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && \App\Offers\Signing::picks($position->stage) && auth()->user()?->canEditDeals())
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
                    @php $officeContacts = $deal && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canEditDeals()
                        && (\App\Offers\Signing::awaitsSlots($position->stage) || \App\Offers\Signing::picks($position->stage) || (\App\Offers\Signing::booked($deal) && $position->waitsFor() !== \App\Workflow\WaitsFor::Manager))
                        ? \App\Offers\Signing::office($deal)['contacts'] : []; @endphp
                    @if ($officeContacts)
                        <x-ui.info class="mt-3">
                            @foreach ($officeContacts as $c)
                                <x-ui.info-row icon="user">{{ trim($c['name'].($c['ext'] ? ', вн. '.$c['ext'] : '').($c['phone'] ? ', '.$c['phone'] : ''), ', ') }}</x-ui.info-row>
                            @endforeach
                        </x-ui.info>
                    @endif
                    {{-- Счёт поставщика на шаге оплаты (16.11.2026, владелец: «непонятно, показан ли ему счёт, пишет, что я должен ему что-то
                         показывать»): показан — строкой «Счёт поставщика показан менеджеру» со временем, и общей кнопки «Показать менеджеру»
                         тогда нет (она — в «···» разбора писем); не показан — красная строка ниже. Время — `shown_at` у файла
                         (`SupplierInvoice::mark`), у прежних — последний показ по сделке. --}}
                    @php
                        $billShown = $deal && \App\Offers\SupplierInvoice::onPay($deal, $stage) && auth()->user()?->canEditDeals() ? \App\Offers\SupplierInvoice::shown($offer)->first() : null;
                        $billShownAt = $billShown ? (($at = $billShown->getCustomProperty('shown_at')) ? \Illuminate\Support\Carbon::parse($at) : (\App\Offers\Disclosures::shown($deal)->first()?->created_at ?? $billShown->updated_at)) : null;
                    @endphp
                    @if ($billShown)
                        <x-route.task-row icon="check" static>
                            Счёт поставщика показан менеджеру
                            <x-slot:sub>{!! \App\Support\Ago::html($billShownAt, true, words: false) !!}</x-slot:sub>
                        </x-route.task-row>
                    @endif
                    {{-- Письмо поставщика ждёт разбора — строкой в шаге, а не лаймовой плашкой над путём (10.11.2026). --}}
                    @if ($deal && $position->track === \App\Workflow\Track::Sale && auth()->user()?->canEditDeals())<x-deal.reply-review :deal="$deal" line :manual="false"/>@endif
                    {{-- Менеджер платит поставщику по его счёту, а счёта не видит (10.11.2026, 0790/046/11079/26) — красной строкой, а главная
                         кнопка шага — «Показать счёт» (20.11.2026, владелец: «тут должна быть кнопка „Показать счёт“»). --}}
                    @php $billNeeded = $deal && \App\Offers\SupplierInvoice::onPay($deal, $stage) && auth()->user()?->canEditDeals() && \App\Offers\SupplierInvoice::shown($offer)->isEmpty(); @endphp
                    @if ($billNeeded)
                        <div class="contents" data-controller="sheet" data-action="invoice-pay-{{ $position->id }}:open@window->sheet#open">
                            <x-route.task-row icon="file" urgent aria-controls="invoice-pay-{{ $position->id }}">Менеджер не видит счёт поставщика</x-route.task-row>
                            <x-deal.supplier-invoice-sheet :id="'invoice-pay-'.$position->id" :deal="$deal"/>
                        </div>
                    @endif
                    {{-- Наш счёт и договор на шаге оплаты (16.11.2026, владелец: «чтобы не удивлялись, почему не платят по счёту»): не
                         отправлены менеджеру — рыжей строкой со шторкой «Отправить менеджеру», отправлены — строкой с галкой. --}}
                    @if ($deal && $stage->isPayStep() && $deal->buyer_id && auth()->user()?->canEditDeals())
                        @foreach ($invoices->filter(fn ($i) => $i->direction === 'issued') as $i)
                            <x-deal.send-task :deal="$deal" :invoice="$i"/>
                        @endforeach
                        @if ($deal->hasContract() && \App\Offers\DealContract::for($deal)->isReady())
                            <x-deal.send-task :deal="$deal"/>
                        @endif
                    @endif
                    {{-- Оплата поставщику по договору комиссии (14.11.2026): назначенная им сумма не та, что мы переведём, — строкой дела,
                         к блоку «… по договору комиссии» в «Деньгах» (там обе суммы, назначение и реквизиты). --}}
                    @if ($deal && $stage->paysOut() && auth()->user()?->canManageCrm() && ($sp = $deal->contract?->supplier_price) !== null && ($sc = $deal->supplierCost()) !== null && $sp !== $sc)
                        <x-route.task-row icon="file" urgent href="#money">По договору комиссии {{ \App\Support\Money::rub($sp) }}, у нас {{ \App\Support\Money::rub($sc) }}</x-route.task-row>
                    @endif
                    {{-- Документ об оплате (10.11.2026): на шаге оплаты админ прикладывает за менеджера (чек прислал в чат), дальше — одним
                         PDF для страховой (`PaymentProofPdf`), пока письмо об оплате не ушло. --}}
                    @php $payProof = $deal && auth()->user()?->canEditDeals() ? \App\Offers\Documents\PaymentProofPdf::files($deal)->isNotEmpty() : false; @endphp
                    @if ($payProof && ($stage->isPayStep() || ($stage->waits_for === WaitsFor::Supplier && $stage->template_id && $position->letterStatus() !== 'sent')))
                        <x-route.task-row icon="file" :href="'/work/deals/'.$deal->id.'/payment-proof.pdf'" target="_blank">
                            Документ об оплате
                            <x-slot:sub>PDF для страховой</x-slot:sub>
                        </x-route.task-row>
                        {{-- Платёжка на самом деле — чек нашего счёта (16.11.2026, «Это за другое»): пока письмо страховой не ушло. --}}
                        @if (! $stage->isPayStep() && ($primeBill = \App\Billing\Actions\SwapPaymentProof::invoiceTarget($deal)))
                            @php $primeName = $primeBill->seller->config()['name'] ?? 'по счёту'; @endphp
                            <form method="post" action="/work/deals/{{ $deal->id }}/payment-proof/to-invoice" class="mt-2" data-turbo-confirm="Это чек оплаты {{ $primeName }}? Он ляжет сообщением об оплате счёта {{ $primeBill->label() }}, шаг вернётся на «Оплату» и снова будет ждать чек поставщику" data-turbo-confirm-label="Перенести">
                                @csrf<x-ui.button size="sm" variant="secondary">Это оплата {{ $primeName }}</x-ui.button>
                            </form>
                        @endif
                    @endif
                    @if ($position->fields())
                        <div class="mt-2 text-sm">@foreach ($position->fields() as $k => $v)<div><span class="text-ink-muted">{{ $k === 'contacts' ? 'Контакты' : (collect($stage->staff_fields)->firstWhere('key', $k)['label'] ?? $k) }}:</span> {{ is_array($v) ? collect($v)->map(fn ($c) => is_array($c) ? trim(($c['name'] ?? '').' '.($c['phone'] ?? '')) : (string) $c)->implode('; ') : $v }}</div>@endforeach</div>
                    @endif
                    @php $doneNames = $subs->where('state', \App\Workflow\Substeps::DONE)->pluck('name')->map(fn ($v) => mb_strtolower($v)); @endphp
                    @foreach ($answers as $r)
                        {{-- Ответ, что уже стоит пройденным подпунктом с именем и временем, второй строкой не повторяем (10.11.2026). --}}
                        @unless ($doneNames->contains(mb_strtolower($r->answer['label'] ?? $r->answer['exit'] ?? '')))
                        <div class="mt-2 text-sm"><span class="text-ink-muted">{{ $answerer($r) }}:</span> «{{ $r->answer['label'] ?? $r->answer['exit'] ?? $r->title }}»@if (($r->answer['closed_by'] ?? null) === 'staff') <span class="text-ink-muted">(за него {{ $answeredBy->get($r->answer['by'] ?? 0)?->shortName() ?? 'сотрудник' }})</span>@endif @if ($r->done_at)<span class="nums text-ink-dim">{{ \App\Support\Ago::html($r->done_at, true, words: false) }}</span>@endif</div>
                        @endunless
                        @foreach ($r->answer['fields'] ?? [] as $k => $v)<div class="text-sm"><span class="text-ink-muted">{{ collect($r->fields)->firstWhere('key', $k)['label'] ?? $k }}:</span> {{ $r->shown($k, $v) }}</div>@endforeach
                        {{-- Платёжка шага оплаты и подписанный договор — файлом в «Продаже» (одним PDF), в ходе сделки второй раз не показываем
                             (16.11.2026, договор — 17.11.2026). --}}
                        @foreach ((in_array($r->answer['exit'] ?? null, \App\Offers\Documents\PaymentProofPdf::EXITS, true) || ($r->asks === \App\Workflow\Asks::Document && $r->stage?->aboutContract())) ? [] : $r->getMedia('files') as $f)<x-ui.file :name="$f->file_name" :mime="$f->mime_type" :bytes="$f->size" href="/files/{{ $f->id }}" class="py-1"/>@endforeach
                    @endforeach
                    @foreach ($claims as [$invoice, $p])
                        <div class="mt-3 rounded-(--radius-m) bg-surface-2 p-3">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <span class="text-sm text-ink-muted">Менеджер сообщил об оплате</span>
                                <span class="nums font-semibold">{{ Money::rub($p->amount) }}</span>
                                <span class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($p->paid_at, words: false) }}@if ($p->ref), № {{ $p->ref }}@endif</span>
                            </div>
                            @if ($slip = $p->slip())<x-ui.file name="Платёжка" :mime="$slip->mime_type" :bytes="$slip->size" :size="\App\Support\Ago::at($slip->created_at, true, words: false)" :href="\App\Support\Docs::media($slip)['url']" class="mt-2"/>@endif
                            <div class="claim-acts mt-2">
                                <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступила</x-ui.button></form>
                                <div data-controller="sheet" class="contents">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Не поступила</x-ui.button>
                                    <x-ui.sheet id="reject-{{ $p->id }}" title="Оплата не поступила">
                                        <form method="post" action="/work/payments/{{ $p->id }}/reject" class="flex flex-col gap-3">@csrf<x-ui.field name="reason" label="Что не так" placeholder="Денег на счёте нет, платёжка не читается"/><x-ui.sheet-foot variant="secondary">Не поступила</x-ui.sheet-foot></form>
                                    </x-ui.sheet>
                                </div>
                                <x-deal.claim-other :payment="$p"/>
                            </div>
                        </div>
                    @endforeach
                    {{-- Услуги подбора гаражной на шаге оплаты поставщику не вписываются (20.11.2026, владелец: «услуги подбора ваще в другом
                         месте указываются») — только в «Деньгах» сделки. Главное здесь — «Показать счёт» поставщика. --}}
                    @if ($wantsInvoice || $billNeeded || $moves->isNotEmpty() || $stage->template_id || $requests || $payNow->isNotEmpty())
                        @php
                            // Письмо с реквизитами без реквизитов — не кнопкой, а чего не хватает (03.11.2026, владелец: «почему
                            // „Написать поставщику“, если покупатель так и не заполнен»).
                            $requisitesGap = $stage->isRequisitesStep() && $deal ? $deal->requisitesGap() : null;
                            // Письмо с реквизитами ещё не ушло (18.11.2026, владелец: три случая) — покупатель в самом шаге, как в «Продаже»,
                            // кнопка по реквизитам: `ready` — «Отправить реквизиты», `partial` — она же с переспросом, `none` — «Запросить реквизиты».
                            $reqState = $stage->isRequisitesStep() && $deal && in_array($position->letterStatus(), ['unsent', 'bounced'], true) ? $deal->requisitesState() : null;
                            $buyerPays = $deal && ! $deal->schemeOf()->paysCar() && ! $deal->isGarageUs();
                            // Поставщик отвечает списком (Каркаде, `DealFormat::Wholesale`): отметить ответ по всем его сделкам разом.
                            $waiting = $deal && $offer->vendor?->deal_format === \App\Vendors\DealFormat::Wholesale && $stage->isAgreement() && $stage->waits_for === WaitsFor::Supplier
                                ? \App\Offers\Deal::awaitingSupplier($offer->vendor_id) : collect();
                            $invoiceButton = $wantsInvoice && $position->gap() !== 'send' && ! ($position->gap() === 'ros_requisites' && ! auth()->user()?->isParkAdmin());
                            // Сколько кнопок в ряду: от трёх лаймовая встаёт своей строкой во всю ширину (`.step-acts--lead`, 17.11.2026).
                            $actCount = ($requests ? 1 : 0) + ($invoiceButton ? 1 : 0) + ($billNeeded ? 1 : 0) + $payNow->count() + $moves->count()
                                + ($stage->template_id ? 1 : 0) + ($waiting->count() > 1 ? 1 : 0);
                        @endphp
                        {{-- `.step-acts`: кнопки делят ширину дорожки, перенеслись — каждая во всю ширину (05.11.2026, владелец про
                             три узкие дорожки редактора: «вёрстка — треш»; кнопки разной длины стояли лесенкой). Лаймовая (`step-main`) —
                             первой, от трёх кнопок — своей строкой (17.11.2026, владелец: «главное действие — „Спросить менеджера“», а
                             больше всех была серая). --}}
                        <div @class(['step-acts mt-3', 'step-acts--lead' => $actCount >= 3])>
                            {{-- Заявка поставщику пачкой: окно письма со списком файлом, отправили — все её сделки дальше. --}}
                            @if ($requests)
                                <x-mail.window-button :url="'/work/mail/new?request='.$offer->vendor_id.'&then=close'" :label="'Заявка поставщику ('.$requests.')'" class="btn-s btn-accent step-main"/>
                            @endif
                            {{-- «Отправить счёт» — строкой дела выше со шторкой (`x-deal.send-task`), кнопкой здесь не дублируем. --}}
                            @if ($wantsInvoice && $position->gap() !== 'send')
                                {{-- Счета ставятся сами (`SyncDealInvoices`); гаражной не хватает нашей доли — вписать её в «Деньгах» сделки. --}}
                                @php $action = \App\Workflow\Position::gapAction($position->gap(), $deal); @endphp
                                {{-- Реквизиты Р.О.С. правятся на парковке: у кого её нет, кнопка вела бы в стену (18.10.2026). --}}
                                @if ($position->gap() === 'ros_requisites' && ! auth()->user()?->isParkAdmin())
                                    <p class="text-sm text-ink-muted">Ждём реквизиты Р.О.С.</p>
                                @elseif (in_array($position->gap(), ['share', 'commission'], true))
                                    {{-- Деньги сделки — шторкой «Деньги сделки» / «Цена и вознаграждение» тут же (`x-deal.money`, тот же пункт «⋯»):
                                         ссылка на страницу её не открывала (19.11.2026). --}}
                                    <button type="button" class="btn btn-s btn-accent step-main" data-controller="emit" data-action="emit#send" data-emit-event-param="deal-money-{{ $deal->id }}:open">{{ $action['button'] }}</button>
                                @else
                                    <a href="{{ $action['url'] }}" class="btn btn-s btn-accent step-main" data-turbo-frame="_top">{{ $action['button'] }}</a>
                                @endif
                            @endif
                            @if ($billNeeded)
                                <button type="button" class="btn btn-s btn-accent step-main" data-controller="emit" data-action="emit#send" data-emit-event-param="invoice-pay-{{ $position->id }}:open">Показать счёт</button>
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
                                {{-- Ждём ответа поставщика на ушедшее письмо (платёжка, заявка) — его исходы серыми (16.11.2026, владелец: «„Оплата
                                     получена“ нажимать нежелательно — ждём подтверждения в письме поставщика»). --}}
                                {{-- «Поставщик подтвердил оплату» — серой всегда: жмут, только когда поставщик написал (письмо проводит шаг само). --}}
                                @php $variant = $i === 0 && ! $wantsInvoice && $payNow->isEmpty() && $position->letterStatus() !== 'unsent'
                                    && ! ($stage->waits_for === WaitsFor::Supplier && $position->letterStatus() === 'sent')
                                    && $exit->label !== \App\Workflow\Presets\Route::SUPPLIER_PAID ? 'primary' : 'secondary'; @endphp
                                @php $main = $variant === 'primary' ? 'step-main' : null; @endphp
                                @if ($exit->to?->offer_state === \App\Offers\OfferState::Open && in_array($offer->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery], true))
                                    {{-- «Выставить в продажу» — сейчас или в слот, как кнопка редактора. --}}
                                    <div data-controller="sheet" @class([$main])>
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-offer.publish-sheet :offer="$offer" id="exit-{{ $exit->id }}" action="/offers/{{ $n }}/exit/{{ $exit->id }}"/>
                                    </div>
                                @elseif ($deal && \App\Offers\Signing::confirmExit($exit))
                                    {{-- «Запись подтверждена» — на какое из названных менеджером времён записал поставщик (`ConfirmSigning`). --}}
                                    <div data-controller="sheet" @class([$main])>
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="confirm-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.signing-confirm-sheet :id="'confirm-'.$exit->id" :deal="$deal" :exit="$exit" :reply="$signingReply"/>
                                    </div>
                                @elseif ($deal && \App\Offers\Signing::slotsExit($exit))
                                    {{-- Свободное время поставщика — шторкой: адрес и время, менеджер выберет плиткой (`Offers\Signing`). --}}
                                    <div data-controller="sheet" @class([$main])>
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="slots-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.signing-slots-sheet :id="'slots-'.$exit->id" :deal="$deal" :exit="$exit" :reply="$signingReply"/>
                                    </div>
                                @elseif (\App\Offers\SupplierInvoice::needs($deal, $exit))
                                    {{-- На оплату поставщику менеджером — только со счётом: шторка выбора счёта (`SupplierInvoice`). --}}
                                    <div data-controller="sheet" @class([$main])>
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open" aria-controls="invoice-{{ $exit->id }}">{{ $label($exit) }}</x-ui.button>
                                        <x-deal.supplier-invoice-sheet :id="'invoice-'.$exit->id" :deal="$deal" :exit="$exit"/>
                                    </div>
                                @elseif ($exit->staffFields())
                                    <div data-controller="sheet" @class([$main])>
                                        <x-ui.button type="button" size="sm" :variant="$variant" data-action="sheet#open">{{ $label($exit) }}</x-ui.button>
                                        <x-ui.sheet id="exit-{{ $exit->id }}" :title="$exit->label">
                                            <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" class="flex flex-col gap-4">
                                                @csrf
                                                {{-- Контакт и адрес из письма — уже вписаны (`Handover::prefill`), поправить можно. --}}
                                                @php
                                                    $prefill = \App\Offers\Handover::prefill($exit->to, $offer);
                                                    // Владелец и его телефон — списком контактов: их бывает несколько (17.11.2026).
                                                    $cf = \App\Offers\Handover::contactFields($exit->to);
                                                    $rest = $cf ? array_values(array_filter($exit->staffFields(), fn ($f) => ! in_array($f['key'], $cf, true))) : $exit->staffFields();
                                                @endphp
                                                @if ($cf)
                                                    @php $byKey = collect($exit->staffFields())->keyBy('key'); @endphp
                                                    <x-route.contacts :contacts="\App\Cars\OwnerContacts::of($offer)" :labels="[$byKey[$cf['name']]['label'], $byKey[$cf['phone']]['label']]" :id="'exit-'.$exit->id.'-contacts'"/>
                                                @endif
                                                @if ($rest)
                                                    <div class="fields">
                                                        @foreach ($rest as $field)
                                                            <x-route.field :field="$field" :name="'fields['.$field['key'].']'" :value="$prefill[$field['key']] ?? $field['default'] ?? null"/>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                <x-ui.sheet-foot>{{ $label($exit) }}</x-ui.sheet-foot>
                                            </form>
                                        </x-ui.sheet>
                                    </div>
                                @else
                                    {{-- Закрыть сделку, пока автомобиль не забрали (18.10.2026), — только переспросив. --}}
                                    @php $confirm = \App\Offers\Deal::closes($exit) && $offer->deal?->closeBlocker() ? 'Автомобиль ещё не забрали. Закрыть сделку?'
                                        : $exit->confirm; @endphp
                                    @if ($asks($exit) && $deal?->needsPrecheck($stage))
                                        {{-- Менеджер просит сначала ПТС и осмотр (20.11.2026, Корабельников по грузовым Альфы, `Deal::needsPrecheck`):
                                             доп. запрос с отмеченными «Запросить ПТС» и «Согласовать осмотр» — главной; пропустить — рядом. --}}
                                        <x-mail.window-button :url="'/work/mail/new?offer='.$n.'&extra=1'.\App\Offers\Deal::precheckPicks()" label="Отправить доп. запрос" icon="mail" class="btn-s btn-accent step-main"/>
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" data-controller="ask-consent" data-action="submit->ask-consent#ask">
                                            @csrf<input type="hidden" name="then_take" value="" data-ask-consent-target="take"><input type="hidden" name="skip_precheck" value="1"><x-ui.button size="sm" variant="secondary">Сразу спросить менеджера</x-ui.button>
                                        </form>
                                    @elseif ($asks($exit))
                                        {{-- Спросить или «Уже согласился» — одной шторкой (`ask_consent_controller`, 28.10.2026). --}}
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" data-controller="ask-consent" data-action="submit->ask-consent#ask" @class([$main])>
                                            @csrf<input type="hidden" name="then_take" value="" data-ask-consent-target="take"><x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                        </form>
                                    @else
                                        @php
                                            $next = $exit->consequence();
                                            // Ответ поставщика, а он после нашего письма молчит — переспросить (18.11.2026, 8771/046/00635/26).
                                            $silent = ! $confirm && $offer->deal && \App\Offers\InsurerReplies::isSupplierAnswer($exit) ? \App\Offers\InsurerReplies::silentSince($offer->deal) : null;
                                            $confirm = $silent ? 'Поставщик ещё не ответил на письмо от '.\App\Support\Ago::at($silent, time: true, words: false).'. Точно «'.$exit->label.'»?' : $confirm;
                                        @endphp
                                        <form method="post" action="/offers/{{ $n }}/exit/{{ $exit->id }}" @class([$main]) @if ($confirm) data-turbo-confirm="{{ $confirm }}" @if ($next) data-turbo-confirm-text="Дальше: {{ $next }}" @endif @elseif ($next) data-turbo-confirm="{{ $label($exit) }}?" data-turbo-confirm-text="Дальше: {{ $next }}" data-turbo-confirm-label="{{ $label($exit) }}" @endif>
                                            @csrf<x-ui.button size="sm" :variant="$variant">{{ $label($exit) }}</x-ui.button>
                                        </form>
                                    @endif
                                @endif
                            @endforeach
                            @if ($reqState && $buyerPays)
                                {{-- Покупатель и его реквизиты — тем же видом, что в «Продаже»: посмотреть, поправить, заполнить за менеджера. --}}
                                <div class="list order-first w-full"><x-deal.buyer :deal="$deal"/></div>
                            @endif
                            @if ($reqState === 'none' && $buyerPays && $deal->buyer_id)
                                <form method="post" action="/offers/{{ $n }}/ask-buyer" class="step-main order-first">@csrf<button class="btn btn-accent btn-s"><x-ui.icon name="chat" class="size-4"/>Запросить реквизиты</button></form>
                                @if ($deal->buyer_asked_at)<p class="w-full text-sm text-ink-muted">Запросили у менеджера {{ \App\Support\Ago::at($deal->buyer_asked_at, time: true) }}</p>@endif
                            @elseif ($reqState === 'none' || ($requisitesGap && ! $buyerPays))
                                <p class="order-first w-full text-sm text-ink-muted">{{ $requisitesGap }}, письмо поставщику пока не написать @if (auth()->user()?->canEditDeals())<a href="{{ \App\Support\Surface::Park->url('/money/parties') }}" class="btn btn-s btn-quiet ml-1" data-turbo-frame="_top">Заполнить</a>@endif</p>
                            @elseif ($reqState)
                                {{-- Отправить реквизиты — окно письма поставщику; не всё заполнено — сначала переспросить. --}}
                                @php $missing = $reqState === 'partial' ? ($deal->contract?->buyer?->party?->gapWord() ?? mb_lcfirst($requisitesGap)) : null; @endphp
                                <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() === 'bounced' ? 'Отправить снова' : 'Отправить реквизиты'" class="btn-accent btn-s step-main order-first"
                                    :data-emit-confirm-param="$missing ? 'Заполнены не все данные: '.$missing.'. Отправить реквизиты?' : null"/>
                            @elseif ($stage->template_id)
                                {{-- Письмо не ушло — это и есть дело шага: лаймовой первой; ушло — серой «Написать ещё». --}}
                                @if ($position->letterStatus() === 'failed' && ($failedId = $position->payload['letter_failed']['id'] ?? null))
                                    {{-- Не ушло с нашей стороны — то же письмо ещё раз (`MailController::resend`). --}}
                                    <form method="post" action="/work/mail/messages/{{ $failedId }}/retry" class="step-main order-first">@csrf<button class="btn btn-accent btn-s"><x-ui.icon name="send" class="size-4"/>Повторить отправку</button></form>
                                @elseif (in_array($position->letterStatus(), ['unsent', 'bounced'], true))
                                    {{-- Письмо — окном писем, как везде (28.10.2026: ссылка уводила на отдельную страницу); ушло — в окне его ветка, закрыли — шаг перечитан. --}}
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() === 'bounced' ? 'Написать снова' : ($deal && str_contains((string) $stage->template?->body, 'Документ об оплате') ? 'Отправить страховой' : 'Написать поставщику')" class="btn-accent btn-s step-main order-first"/>
                                @else
                                    <x-mail.window-button :url="$offer->vendorLetterUrl($stage)" icon="send" :label="$position->letterStatus() ? 'Написать ещё' : 'Письмо вендору'" class="btn-s"/>
                                @endif
                            @endif
                            {{-- Поставщик отвечает списком (Каркаде, `DealFormat::Wholesale`): отметить ответ по всем его сделкам разом. У Альфы
                                 (03.11.2026) кнопка «Ответ по списку (85)» стояла зря — она отвечает по каждой машине. --}}
                            @if ($waiting->count() > 1)
                                <div data-controller="sheet">
                                    <x-ui.button type="button" size="sm" variant="secondary" data-action="sheet#open">Ответ по списку ({{ $waiting->count() }})</x-ui.button>
                                    <x-deal.supplier-answer :deals="$waiting" :id="'supplier-answer-'.$position->id"/>
                                </div>
                            @endif
                        </div>
                        {{-- Что будет после кнопки (03.11.2026, «сотрудникам страшно нажимать») — в переспросе кнопки, а не строкой «Дальше:»
                             под ней (10.11.2026, владелец: «месиво»). --}}
                    @endif
                    @if ($subs->where('state', \App\Workflow\Substeps::WAIT)->isNotEmpty())
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
</div>
