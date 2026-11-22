{{-- Строка таблицы предложений (CRM), по образцу «Наличия» парковки. Ячейка в два этажа: иконка типа ТС и название с
     «рекомендуем», под ним логотип страховой с номером убытка, номер предложения, приём (таймер или состояние, цветом
     по тону) и подтверждения; от 640 они встают своими столбцами (вендор с номером убытка — одним). Справа цена
     продажи (у черновика без неё — чип «Оценить»), последним столбцом закупочная; на телефоне она под ценой.
     У черновика нет ни номера (он ещё не выставлен), ни слова «черновик»: его и так видно по чипу «Оценить» и цене.
     Черновик из парковки — исключение: на месте состояния «парковка с …» (дата приёма ТС). Последний столбец —
     когда заведено.
     В галерее вместо подтверждений — интерес. Нажатие — карточка; data-todo — у человека по строке есть дело,
     по ним карточка идёт «Дальше» («Оценить»). checkable — галочка первым столбцом («Оцененные», «Публикация»; group — ключ
     слота для галочки группы); не готовое к продаже вместо галочки получает красное «нет фото» второй строкой, срок
     страховой раньше ближайшего слота — тоже красным. table — столбцы вкладки (`OfferController::table`): ячейки идут в
     его порядке и с его классами, шапка и группы — по тому же описанию; в поиске (`mixed`) — все столбцы. --}}
@props(['offer', 'table', 'gallery' => false, 'group' => null, 'arrive' => null])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    // В поиске (список смешанный) про строку важнее всего, где она: вкладкой, без часов и «выбрать».
    $mixed = (bool) $table->context('mixed');
    $checkable = $table->has('pick');
    // Черновик завела почта (`AutoOffer`), файлы и документы догружаются (`AutoScan::states`): что уже знаем из письма —
    // номер, вендор, закупочная, машина из темы — стоит сразу, остальное полосами; ход — строкой под названием.
    $arriving = $arrive['state'] ?? null;
    $busy = in_array($arriving, ['ghost', 'files', 'reading'], true);
    // Что сказать о строке — один расчёт с плиткой (`Offers\OfferRow`): подтверждения, слово места, «Оценить», чего не хватает.
    $r = \App\Offers\OfferRow::for($offer, auth()->user(), $gallery, $checkable, $busy);
    [$admin, $timer, $count, $countWord, $line, $stateWord, $tone, $draft, $unpriced, $rate, $slot, $missing, $todo, $vendor, $noVin]
        = [$r->admin, $r->timer, $r->count, $r->countWord, $r->line, $r->stateWord, $r->tone, $r->draft, $r->unpriced, $r->rate, $r->slot, $r->missing, $r->todo, $r->vendor, $r->noVin];
    // «Все» и поиск (16.11.2026, владелец: «в состоянии писать именно в какой они вкладке, а не сколько ждёт») — словом вкладки,
    // той же раскладкой, что группы и чип «Вкладка» (`OfferTabs`).
    $tabWord = $table->context('tabs') && $admin ? (($tabKey = \App\Offers\OfferTabs::of($offer)) === \App\Offers\OfferTabs::OTHER ? $offer->crmTab() : \App\Offers\OfferTabs::label($tabKey)) : null;
    [$corrections, $correcting, $proposes, $waits, $garageBids, $priceBids] = [$r->corrections, $r->correcting(), $r->proposes(), $r->waits(), $r->garageBids, $r->priceBids];
    // Приём закрылся, а подтверждения есть — ход наш: «выбрать» оранжевым; без них — «приём закрыт».
    $pick = $admin && $offer->state === OfferState::Open && ! $timer && $offer->bids_close_at;
    // Закупочной нет — «Заполнить» в её ячейке (вкладка «Без закупочной цены», дело модератора); там же столбца цены нет,
    // и на телефоне справа стоит закупочная.
    $nofloor = $draft && ! $offer->floor_price && ! $offer->isScheduled();
    $floorSide = (bool) $table->context('floorSide');
    $has = fn (string $k) => $table->has($k);
    $when = match ($table->context('when')) {
        'published' => $offer->published_at,
        // «На оценке у менеджеров» — с первой открытой просьбы (`OfferController::ESTIMATE_SINCE`).
        'tab' => $offer->estimate_since ?? $offer->tab_at,
        'created' => $offer->created_at,
        default => null,
    };
    // Аватар у даты: во вкладке шага — кто перевёл сюда (`tab_by`, 18.10.2026), иначе — кто завёл (у черновика).
    $byTab = $table->context('when') === 'tab';
    $who = $byTab ? ($offer->estimate_since ? $offer->estimateBy : $offer->tabBy) : $offer->moderator;
    // Человека нет — система (26.10.2026, владелец: «пустота мне не нравится»): завела почта — конверт, Мигторг, парковка —
    // искра. Во вкладке шага — перевела система (`tab_by` пишется с 18.10.2026, раньше пусто у всех — там не угадываем).
    $system = ! $who && ($byTab ? (bool) $offer->tab_at?->gte('2026-10-18') : (bool) $offer->created_via);
    // «Срок от вендора раньше ближайшего выхода» — о выставлении в продажу. Только без срока обязывающего: он стоит справа
    // своим таймером и горит сам, а эта отметка — с датой торгов — была бы рядом второй, другой датой (22.11.2026, владелец:
    // «странно, что по две даты»); в «Без продажной цены» (галочки на оценку) продавать нечего.
    $late = $checkable && $draft && ! $unpriced && ! $offer->binding_until && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($offer->slot_at ?? \App\Offers\Slots::nearest());
@endphp
{{-- Правый край строки. На ПК — ячейка цены (и закупочной у «Без закупочной»), на телефоне — концы двух строк ячейки названия
     (15.11.2026, владелец: «зачем обрезается, если до „вчера“ ещё куча места»): столбец справа был шириной в самую длинную цену
     и резал название над коротким «вчера»; теперь каждая строка сама решает, сколько места у текста. Наверху — дата (или срок
     обязывающего), «переоценка» и цена; внизу — «в минус», подтверждения или закупочная. Цена считается один раз (`$main`). --}}
@php
    $rate = $rate && ! ($has('state') && \App\Http\Admin\OfferController::summary(request()->query('preset')) !== null);
    $endCol = $floorSide ? 'floor' : ($table->has('price') ? 'price' : null);
    // «Все» (17.11.2026, владелец: «справа лучше не цену, а дату и время обязывающего с иконкой и сколько дней и часов она уже в
    // этой вкладке»): наверху срок обязывающего, внизу — сколько во вкладке (`OfferTabs::sinceSql`, столбец «Во вкладке»).
    $since = $has('since') && $offer->tab_since ? \Illuminate\Support\Carbon::parse($offer->tab_since) : null;
    // Справа на телефоне уже стоит «⏱ 20.11» (`x-offer.row-meta`) — у номера второй таймер не нужен (18.11.2026, владелец).
    $metaTimer = $endCol && ($since || ! $has('binding'));
    ob_start();
@endphp
        @if ($busy && $rate)

        @elseif ($rate)<x-offer.rate-chip :offer="$offer" :chip="false"/>
        @elseif ($unpriced)
        {{-- Корректировка («Оцененные», 11.10.2026): своя цена менеджера — «620 000 → пилюля», как «закупочная → продажи»;
             согласились — галочка с числом у цены; ждём — аватары и срок под ценой вместо закупочной. --}}
        @elseif ($price->shown() && $correcting)
            @if ($proposes)<span class="text-ink-muted">{{ $price::money($price->to) }}&nbsp;→</span> <x-offer.correction-chip :offer="$offer" part="proposal"/>@else{{ $price::money($price->to) }} <x-offer.correction-chip :offer="$offer" part="agreed"/>@endif
            @if ($waits)<span class="mt-1 flex justify-end sm:mt-0 sm:ml-1.5 sm:inline-flex"><x-offer.correction-chip :offer="$offer" part="waiting"/></span>@endif
        @elseif ($price->shown()){{ $price::money($price->to) }}
        @elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
@php
    $main = ob_get_clean();
    ob_start();
@endphp
@if ($since)<x-offer.row-meta :offer="$offer" always/>@elseif ($endCol === 'price')<x-offer.row-meta :offer="$offer" :when="$when" :binding="! $has('binding')"/>@if ($admin && $offer->reprice_pending)<span class="text-sm text-urgent">переоценка</span>@endif{!! $main !!}@elseif ($endCol === 'floor')<x-offer.row-meta :offer="$offer" :when="$when" :binding="! $has('binding')"/>@endif
@php
    $endTop = ob_get_clean();
    // В строке названия копия лежит внутри ссылки строки — ссылок в ссылке не бывает (чипы оценок и корректировок ведут на ту же
    // строку): у копии они просто метки, нажатие по строке и так открывает окно.
    $endTopLine = preg_replace(['/<a\s[^>]*?class="/', '/<\/a>/'], ['<span class="', '</span>'], $endTop);
    ob_start();
@endphp
@if ($since)<span class="nums text-ink-muted" title="Во вкладке с {{ $since->format('d.m.Y H:i') }}">{{ \App\Support\Ago::since($since, hours: true) }}</span>
@elseif ($endCol === 'price')
    @if ($admin && ! $busy && ($margin = $offer->marginMark()))<x-offer.margin :m="$margin"/>@endif
    @if ($count)<span class="nums {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $countWord }}</span>
    @elseif ($offer->floor_price && $has('floor') && ! ($correcting && $waits))<span @class(['cell-main' => $unpriced]) @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif
@elseif ($endCol === 'floor' && $offer->floor_price)<span class="cell-main" @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>
@endif
@php $endBottom = trim(ob_get_clean()); @endphp
<tr data-detail-key="{{ $n }}" data-search-row id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" @if ($todo) data-todo @endif @if ($offer->claim_ref_key) data-flip="c:{{ $offer->claim_ref_key }}" @endif @if ($arrive) data-controller="arrive" data-arrive-state-value="{{ $arriving }}" data-arrive-subject-value="o:{{ $offer->id }}" @endif @class(['arrive arrive--offer' => $arrive, 'arrive--'.$arriving => $busy, 'arrive--busy' => $busy])>
    {{-- Ячейки — в порядке и с классами описания (`OfferController::table`): шапка и группы сходятся с ними всегда. --}}
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('pick')
    <td class="{{ $col->classes() }}">@unless ($missing && request()->query('preset') !== 'done')<label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-{{ $n }}" data-turbo-permanent name="offers[]" value="{{ $n }}" form="offers-pick" data-pick-target="box" data-group="{{ $group }}" data-action="pick#sync"></span></label>@endunless</td>
        @break
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($busy && ! $offer->brand_id)
        <x-ui.row-link :key="$n"><span class="cell-title"><span class="skeleton arrive-bar w-40 max-w-full"></span></span></x-ui.row-link>
        @else
        <x-ui.row-link :key="$n"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name arrive-late">{{ $offer->title() }}@if ($year = $offer->carRecord()->year)<span class="cell-year"> {{ $year }}</span>@endif</span>@if ($offer->recommended)<x-offer.recommended/>@endif{{ "" }}@if ($offer->isUrgent())<x-offer.urgent/>@endif{{ "" }}@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->proposed())<span class="ml-1.5 inline-flex shrink-0 text-accent-text" title="Предложено из заявок на приём" aria-label="Предложено из заявок на приём"><x-ui.spark class="size-4"/></span>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-car.keys :count="$offer->keys_count"/><x-ui.links :offer="$offer"/>@if ($noVin && ! $has('manager'))<s class="ml-1.5 shrink-0 text-sm text-ink-dim" title="VIN нет">VIN</s>@endif{{ "" }}@if ($offer->intake_closed_at && $offer->state === \App\Offers\OfferState::Draft && request()->query('preset') !== 'done' && $offer->floor_price && ! $has('state'))<span class="ml-1.5 shrink-0 text-sm text-urgent">приём закрыт</span>@endif{{ "" }}@if ($offer->partner_price_at)<span class="ml-1.5 shrink-0 text-sm text-ink-muted" title="Поставщик вписал цену, но ещё не отправил — может поправить">у поставщика</span>@endif{{ "" }}@unless ($has('binding') || $has('vendor'))<x-offer.binding :offer="$offer" icon class="max-sm:hidden"/>@endunless{{ "" }}@if ($endCol)<span class="line-end sm:hidden">{!! $endTopLine !!}</span>@endif</span></x-ui.row-link>
        @endif
        {{-- Телефон: строка переносится, а не режется «…» (владелец 04.10.2026: «поля не должны исчезать»); город, даты и
             прочие столбцы — в «Подробной таблице». --}}
        <span class="cell-sub cell-sub--wrap" data-controller="fitline">
            {{-- Номера — одним неразрывным куском: не влезают — строка ужимается (fitline), а не переносится. --}}
            <span class="fit-core sm:hidden @if ($busy) arrive-type @endif"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref">@unless ($metaTimer)<x-offer.binding :offer="$offer" icon/>@endunless</x-vendor.ref></span>
            @if ($endCol && $endBottom !== '')<span class="line-end sm:hidden">{!! $endBottom !!}</span><span class="line-break sm:hidden"></span>@endif
            @if ($busy)<x-offer.arrive-line :arrive="$arrive"/>@endif
            @if ($mixed || $line)<span class="sub-phrase sm:hidden {{ $tone }}">{{ $tabWord ?? $stateWord }}@if ($tabWord && $offer->isGallery())<span class="text-ink-muted">, в галерее</span>@endif{{ "" }}@if ($line?->who)<span class="text-ink-muted">, {{ $line->who }}</span>@endif</span>
            @elseif ($timer)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($slot && $has('state'))<span class="sm:hidden text-accent-text">{{ $slot }}</span>
            {{-- Телефон, «Опубликованные»: у ждущих решения важнее число подтверждений, дата закрытия — у остальных. --}}
            @elseif ($pick && ! $mixed && ! $count)<span class="sm:hidden nums text-ink-muted">закрыт {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
            @endif
            {{-- «в галерее» в строке убрано (14.11.2026, владелец: «нахер не нужна») — это видно в окне. --}}
            @if ($missing)<span class="text-danger">{{ implode(', ', $missing) }}</span>@endif
            @if ($late)<span class="binding-tag nums" title="Срок от вендора раньше ближайшего выхода"><x-ui.icon name="timer" class="size-3.5 shrink-0"/>{{ \App\Support\Ago::at($offer->insurer_deadline_at, false, words: false) }}</span>@endif
        </span>
    </td>
        @break
        @case('vendor')
    {{-- Вендор и номер убытка одним столбцом, как в «Наличии»: логотип (имя — подсказкой), номер с копированием. --}}
    {{-- Красный таймер срока обязывающего — справа от номера, а не у названия (17.11.2026, владелец: «меньше скакать»). --}}
    <td class="{{ $col->classes() }}"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<span @class(['arrive-type' => $busy])><x-ui.copy-code :value="$offer->claim_ref"/></span>@endif{{ "" }}<x-offer.binding :offer="$offer" icon fit/></span></td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">@if (\App\Offers\OfferNumber::isPublic($offer))<x-ui.copy-code :value="(string) $offer->number"/>@endif</td>
        @break
        @case('city')
    {{-- Город — именем без номера региона: столбец узкий, регион виден в карточке. --}}
    <td class="{{ $col->classes() }} arrive-late">@if ($busy && ! $offer->settlement)<span class="skeleton arrive-bar w-16"></span>@elseif ($offer->settlement)<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $offer->settlement->name }}@if ($offer->sellsElsewhere()) → {{ $offer->saleSettlement?->name }}@endif</x-ui.place>@endif</td>
        @break
        @case('gap')
    <td class="{{ $col->classes() }}"></td>
        @break
        @case('state')
    <td class="{{ $col->classes() }}">
        {{-- Кто ведёт — аватаром перед этапом (имя — подсказкой): именем строка не влезала в ширину. --}}
        {{-- Галерея — окно показа черновика параллельно его вкладке (16.11.2026): тихой меткой после вкладки. --}}
        @if ($tabWord)<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $tabWord }}</span>@if ($offer->isGallery())<span class="ml-1.5 whitespace-nowrap text-sm text-ink-muted">в галерее</span>@endif
        @elseif ($line)<span class="inline-flex max-w-full items-center gap-1.5 align-middle" @if ($line->who) title="{{ $line->who }}" @endif>@if ($line->person && ! $has('manager'))<x-ui.avatar :user="$line->person" :size="22"/>@endif<span class="{{ $tone }}">{{ $stateWord }}</span></span>
        @elseif ($mixed)<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $stateWord }}</span>
        @elseif ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span>
        @elseif ($slot)<span class="text-accent-text">{{ $slot }}</span>
        {{-- Во вкладке «Опубликованные» «выбрать» говорит заголовок группы — в строке когда закрылся приём. --}}
        @elseif ($pick)<span class="nums {{ $count ? 'text-urgent' : 'text-ink-muted' }}">закрыт {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
        @else<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $offer->crmTab() }}</span>@endif
    </td>
        @break
        @case('bids')
    {{-- «2 до 5 440 000, 1 в гараж». --}}
    <td class="{{ $col->classes() }} {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">@if ($gallery){{ $count ?: '' }}@else{{ $priceBids ?: '' }}@if ($priceBids && $offer->top_bid)<span class="ml-1 text-sm text-ink-muted">до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif{{ $priceBids && $garageBids ? ',' : '' }}@if ($garageBids)<span class="ml-1 whitespace-nowrap">{{ $garageBids }} <span class="text-sm">в гараж</span></span>@endif @endif</td>
        @break
        @case('value')
    <td class="{{ $col->classes() }}">{{ $offer->value && \App\Offers\Pricing::valued($offer) ? \App\Support\Money::nums($offer->value) : '' }}</td>
        @break
        @case('floor')
    @if ($floorSide)
    {{-- Закупочная на месте цены («Без закупочной цены») — кто завёл и когда стоит при ней, как при цене. --}}
    <td class="{{ $col->classes() }} phone-off">@if ($busy && $nofloor)<span class="skeleton arrive-bar w-16"></span>@elseif ($offer->floor_price)<span @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif</td>
    @else
    <td class="{{ $col->classes() }}">@if ($offer->floor_price)<span @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif</td>
    @endif
        @break
        @case('price')
    <td class="{{ $col->classes() }} phone-off arrive-late {{ $admin && ($offer->marginMark()['tone'] ?? null) === 'loss' ? 'text-danger' : '' }}">
        {{-- Телефон: когда заведено — вплотную слева от цены (10.10.2026, начальник: с телефона непонятно, что когда создано);
             сегодня — время, иначе дата; аватара нет (15.11.2026, владелец); горит срок обязывающего — он вместо даты
             (`x-offer.row-meta`). Столбец «Создано» в краткой таблице спрятан. --}}
        <x-offer.row-meta :offer="$offer" :when="$when" :binding="! $has('binding')"/>
        {{-- Переоценка — перед ценой (14.11.2026, владелец: после цены и чипа «Оценить» строки скакали). --}}
        @if ($admin && $offer->reprice_pending)<span class="mr-1.5 text-sm text-urgent">переоценка</span>@endif
        {{-- Во «Все» чипа «Оценить» нет: «Состояние» и так «Без продажной цены», лаймом на сотне строк — шум (14.11.2026). --}}
        {{-- Чипа «Оценить» в строке нет (15.11.2026, владелец): во вкладках без цены оценить нужно каждую строку — лаймом на
             каждой строке шум и строка в три этажа; нажатие на строку открывает окно с полем цены. Справа — дата и закупочная;
             оценки менеджеров (аватар и сумма, «ждём») — по-прежнему (`rate-chip :chip=false`). --}}
        {!! $main !!}
        {{-- Телефон: подтверждения — под ценой, а не в конце второй строки: там их съедало «…» за номерами (владелец
             04.10.2026: «самое важное, никогда нельзя скрывать»). Есть подтверждения — они вместо закупочной. --}}
        {{-- В минус или почти без наценки (`Offers\Margin`, 14.10.2026) — под ценой, только в CRM админу. --}}
        @if ($admin && ! $busy && ($margin = $offer->marginMark()))<x-offer.margin :m="$margin" class="cell-sub max-sm:!ml-0"/>@endif
        @if ($count)<span class="cell-sub sm:hidden nums {{ $gallery ? '!text-accent-text' : '!text-urgent' }}">{{ $countWord }}</span>
        @elseif ($offer->floor_price && $has('floor') && ! ($correcting && $waits))<span class="cell-sub sm:hidden" @if ($busy) data-arrive-count @endif>@if ($unpriced)<span class="cell-main">{{ \App\Support\Money::nums($offer->floor_price) }}</span>@else{{ \App\Support\Money::nums($offer->floor_price) }}@endif</span>@endif
    </td>
        @break
        @case('manager')
    {{-- Менеджер сделки, иначе гаража (14.11.2026, «Все»). --}}
    @php $mgr = $offer->lastDeal?->buyer ?? $offer->garageCar?->manager; @endphp
    <td class="{{ $col->classes() }}">@if ($mgr)<span class="face flex items-center gap-2" title="{{ $mgr->name }}"><x-ui.avatar :user="$mgr" :size="22"/><span class="truncate">{{ $mgr->shortName() }}</span></span>@endif</td>
        @break
        @case('binding')
    {{-- Срок обязывающего своим столбцом («Все», 14.11.2026): горит — красным, ждёт решения — оранжевым. --}}
    <td class="{{ $col->classes() }}">
        @if ($offer->binding_until && \App\Offers\Binding::matters($offer))
            {{-- Только дата (14.11.2026, владелец: «истёк» и время — колхоз, цвет и так говорит): «04.03», другой год — «04.03.2025». --}}
            @php $blevel = \App\Offers\Binding::of($offer); $bat = $offer->binding_until->copy()->timezone(config('app.timezone')); @endphp
            <span @class(['nums', 'text-danger' => in_array($blevel, ['panic', 'over', 'deal'], true), 'text-urgent' => $blevel === 'undecided'])>{{ $bat->format($bat->isCurrentYear() ? 'd.m' : 'd.m.Y') }}</span>
        @endif
    </td>
        @break
        @case('since')
    {{-- Сколько во вкладке («Все», 17.11.2026): «6д 5ч», с какого момента — подсказкой. --}}
    <td class="{{ $col->classes() }}">@if ($since)<span title="Во вкладке с {{ $since->format('d.m.Y H:i') }}">{{ \App\Support\Ago::since($since, hours: true) }}</span>@endif</td>
        @break
        @case('sold')
        @case('paid')
    {{-- «Продано» — когда нам за неё заплатили, «Оплачено» — когда мы поставщику (14.11.2026, `DealDates`); нажатие — шторка
         `x-deal.dates-sheet` (`SetDealDate`). Пусто — сделки нет (14.11.2026, владелец: «что значит „нет“ и пусто?» — словами):
         денег ещё нет — «ждём» у идущей, «не оплачено» красным у закрытой; поставщику платит менеджер (ДКП собственника) —
         «менеджер». --}}
    <td class="{{ $col->classes() }}">
        @php
            $dd = $offer->lastDeal;
            $dcol = $dd ? ($col->key === 'sold' ? \App\Offers\DealDates::soldColumn($dd) : \App\Offers\DealDates::paidColumn($dd)) : null;
            $dval = $dcol ? $dd->{$dcol} : null;
            $dclosed = $dd?->state === \App\Offers\DealState::Done;
            $dword = $dclosed ? 'не оплачено' : 'ждём';
            $dmiss = $dclosed ? 'text-danger' : 'text-ink-dim';
        @endphp
        @if ($dd && ! $dcol)
            <span class="text-ink-dim" title="Поставщику платит менеджер">менеджер</span>
        @elseif ($dcol && $admin)
            <button type="button" @class(['date-cell underline decoration-dotted underline-offset-4', 'nums' => $dval, $dmiss => ! $dval])
                data-deal-dates="{{ $dd->id }}" data-what="{{ $col->key }}" data-value="{{ $dval?->toDateString() }}" data-car="{{ $offer->titleWithYear() }}">@if ($dval){{ \App\Support\Ago::html($dval) }}@else{{ $dword }}@endif</button>
        @elseif ($dcol)
            @if ($dval){{ \App\Support\Ago::html($dval) }}@else<span class="{{ $dmiss }}">{{ $dword }}</span>@endif
        @endif
    </td>
        @break
        @case('when')
    {{-- Когда заведено (черновик — когда начали): последним, приглушённо и в одну строку; в карточке справа столбца нет.
         Аватаром у даты (у правого края, `.face`): во вкладке шага — кто перевёл сюда, иначе кто завёл черновик. --}}
    <td class="{{ $col->classes() }}">
        @php $face = ($byTab || $draft) ? $who : null; $robot = ($byTab || $draft) && $system; @endphp
        @if ($face || $robot)<span class="face inline-flex items-center gap-2 align-middle">@if ($face)<x-ui.avatar :user="$face" :size="20" title="{{ $face->name }}"/>@else<x-ui.system-avatar :via="$offer->created_via" :size="20"/>@endif @endif{{ \App\Support\Ago::html($when, time: true) }}@if ($face || $robot)</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
