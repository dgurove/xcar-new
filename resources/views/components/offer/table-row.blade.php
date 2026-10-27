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
    // Подтверждения принимает только админ: модератору ни их числа, ни отсчёта приёма, ни «выбрать» — «в продаже».
    $admin = (bool) auth()->user()?->canManageCrm();
    $left = $gallery || ! $admin ? null : $offer->secondsLeft();
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' };
    $count = ! $admin ? 0 : ($gallery ? (int) $offer->interests_count : (int) $offer->active_bids_count);
    // Гаражные подтверждения без суммы — отдельно словом (владелец 05.10.2026: «непонятно, что такое 1»).
    $garageBids = $gallery ? 0 : min($count, (int) $offer->garage_bids_count);
    $priceBids = $count - $garageBids;
    $countWord = $gallery ? 'интерес '.$count : implode(', ', array_filter([$priceBids ? $priceBids.' подтв.' : null, $garageBids ? $garageBids.' в гараж' : null]));
    $timer = $left !== null && $left > 0;
    // Новое (опубликовано за сутки) — голубой точкой, горящее — рыжей, как на витрине (12.10.2026); только админу.
    $mark = $admin && ! $gallery ? $offer->crmTone() : null;
    // Приём закрылся, а подтверждения есть — ход наш: «выбрать» оранжевым; без них — «приём закрыт».
    $pick = $admin && $offer->state === OfferState::Open && ! $timer && $offer->bids_close_at;
    // В поиске (список смешанный) про строку важнее всего, где она: вкладкой, без часов и «выбрать».
    $mixed = (bool) $table->context('mixed');
    $checkable = $table->has('pick');
    // Логотип — из одной выборки вендоров на страницу, а не связью на каждую строку.
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $offer->loadMissing(['parkVehicle:id,car_id,state,accepted_at,created_at', 'deal', 'garageCar']);
    // После продажи — не слово вкладки «Сделки», а этап сделки и менеджер (`DealLine`, 27.10.2026, владелец: «видно, у кого
    // она в сделке и какой этап»): «Оплата, просрочено 3 д» красным, «Гараж: в продаже». Цвет — только просрочка.
    $line = $admin && in_array($offer->state, [OfferState::Sold, OfferState::Delivered, OfferState::Garage], true) ? \App\Offers\DealLine::forOffer($offer) : null;
    $stateWord = $line?->word ?? $offer->crmTab();
    $tone = $line ? $line->tone : $tone;
    $draft = $offer->state === OfferState::Draft;
    $unpriced = $draft && ! $offer->asking_price;
    // «Оценить» — дело админа: модератор цену продажи не ставит, у него на месте слова пусто, закупочная — как была.
    $rate = $unpriced && auth()->user()?->canManageCrm();
    // Корректировки менеджеров у оценённого черновика (11.10.2026) — что показать у цены (`x-offer.correction-chip`).
    $corrections = ! $rate && $offer->state === \App\Offers\OfferState::Draft && auth()->user()?->canManageCrm() && $offer->relationLoaded('openEstimates') ? $offer->openEstimates->filter->isCorrection() : collect();
    $correcting = $corrections->isNotEmpty();
    $proposes = $corrections->contains->proposes();
    $waits = $corrections->contains(fn ($e) => ! $e->isGiven());
    // Закупочной нет — «Заполнить» в её ячейке (вкладка «Без закупочной цены», дело модератора); там же столбца цены нет,
    // и на телефоне справа стоит закупочная.
    $nofloor = $unpriced && ! $offer->floor_price && ! $offer->isScheduled();
    $floorSide = (bool) $table->context('floorSide');
    // data-todo — у человека по строке есть дело (OfferController::todo): по таким карточка идёт «Дальше».
    $todo = auth()->user() && \App\Http\Admin\OfferController::todo($offer, auth()->user());
    // Поставлено в слот — «выйдет сегодня в 16:00» на месте состояния.
    $slot = $offer->isScheduled() ? 'выйдет '.\App\Offers\Slots::phrase($offer->slot_at) : null;
    // Чего не хватает для продажи — у оценённого черновика: галочки у такого нет.
    $missing = $checkable && $draft && ! $slot ? array_map(fn ($m) => 'нет '.match ($m) { 'фотографии' => 'фото', 'марка' => 'марки', 'цена продажи' => 'цены', default => $m }, \App\Offers\Actions\ChangeOfferState::missing($offer)) : [];
    $has = fn (string $k) => $table->has($k);
    $when = match ($table->context('when')) {
        'published' => $offer->published_at,
        'tab' => $offer->tab_at,
        'created' => $offer->created_at,
        default => null,
    };
    // Аватар у даты: во вкладке шага — кто перевёл сюда (`tab_by`, 18.10.2026), иначе — кто завёл (у черновика).
    $byTab = $table->context('when') === 'tab';
    $who = $byTab ? $offer->tabBy : $offer->moderator;
    // Человека нет — система (26.10.2026, владелец: «пустота мне не нравится»): завела почта — конверт, Мигторг, парковка —
    // искра. Во вкладке шага — перевела система (`tab_by` пишется с 18.10.2026, раньше пусто у всех — там не угадываем).
    $system = ! $who && ($byTab ? (bool) $offer->tab_at?->gte('2026-10-18') : (bool) $offer->created_via);
    // VIN на Мигторге не дают — его запрашивают у страховой (05.10.2026): красным у названия, пока продажа жива.
    $noVin = blank($offer->vin) && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true);
    // Черновик завела почта (`AutoOffer`), файлы и документы догружаются (`AutoScan::states`): что уже знаем из письма —
    // номер, вендор, закупочная, машина из темы — стоит сразу, остальное полосами; ход — строкой под названием.
    $arriving = $arrive['state'] ?? null;
    $busy = in_array($arriving, ['ghost', 'files', 'reading'], true);
    $noVin = $noVin && ! $busy;
    $late = $checkable && $draft && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($offer->slot_at ?? \App\Offers\Slots::nearest());
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" @if ($todo) data-todo @endif @if ($offer->claim_ref_key) data-flip="c:{{ $offer->claim_ref_key }}" @endif @if ($arrive) data-controller="arrive" data-arrive-state-value="{{ $arriving }}" data-arrive-subject-value="o:{{ $offer->id }}" @endif @class(['arrive arrive--offer' => $arrive, 'arrive--'.$arriving => $busy, 'arrive--busy' => $busy])>
    {{-- Ячейки — в порядке и с классами описания (`OfferController::table`): шапка и группы сходятся с ними всегда. --}}
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('pick')
    <td class="{{ $col->classes() }}">@unless ($missing)<label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-{{ $n }}" data-turbo-permanent name="offers[]" value="{{ $n }}" form="offers-pick" data-pick-target="box" data-group="{{ $group }}" data-action="pick#sync"></span></label>@endunless</td>
        @break
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($busy && ! $offer->brand_id)
        <x-ui.row-link :key="$n"><span class="cell-title"><span class="skeleton arrive-bar w-40 max-w-full"></span></span></x-ui.row-link>
        @else
        <x-ui.row-link :key="$n"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name arrive-late">@if ($mark && $mark !== 'urgent')<span class="card-tone card-tone--{{ $mark }}" aria-label="{{ match ($mark) { 'hot' => 'Горящее', 'loss' => 'В минус', 'binding' => 'Горит срок обязывающего', default => 'Новое' } }}"></span>@endif{{ $offer->titleWithYear() }}</span>@if ($offer->recommended)<x-offer.recommended/>@endif{{ "" }}@if ($offer->isUrgent())<x-offer.urgent/>@endif{{ "" }}@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->proposed())<span class="ml-1.5 inline-flex shrink-0 text-accent-text" title="Предложено из заявок на приём" aria-label="Предложено из заявок на приём"><x-ui.spark class="size-4"/></span>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-car.keys :count="$offer->keys_count"/><x-ui.links :offer="$offer"/>@if ($noVin)<span class="ml-1.5 text-sm text-danger">Нет VIN</span>@endif{{ "" }}@if ($offer->intake_closed_at && $offer->state === \App\Offers\OfferState::Draft)<span class="ml-1.5 shrink-0 text-sm text-urgent">приём закрыт</span>@endif<x-offer.binding :offer="$offer" icon/></span></x-ui.row-link>
        @endif
        {{-- Телефон: строка переносится, а не режется «…» (владелец 04.10.2026: «поля не должны исчезать»); город, даты и
             прочие столбцы — в «Подробной таблице». --}}
        <span class="cell-sub cell-sub--wrap" data-controller="fitline">
            {{-- Номера — одним неразрывным куском: не влезают — строка ужимается (fitline), а не переносится. --}}
            <span class="fit-core sm:hidden @if ($busy) arrive-type @endif"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>
            @if ($busy)<x-offer.arrive-line :arrive="$arrive"/>@endif
            @if ($mixed || $line)<span class="sm:hidden {{ $tone }}">{{ $stateWord }}@if ($line?->who)<span class="text-ink-muted">, {{ $line->who }}</span>@endif</span>
            @elseif ($timer)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($slot && $has('state'))<span class="sm:hidden text-accent-text">{{ $slot }}</span>
            {{-- Телефон, «Опубликованные»: у ждущих решения важнее число подтверждений, дата закрытия — у остальных. --}}
            @elseif ($pick && ! $mixed && ! $count)<span class="sm:hidden nums text-ink-muted">закрыт {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</span>
            @endif
            {{-- Черновик висит в галерее (08.10.2026) — видно и в шагах работы, с числом интереса. --}}
            @if (! $gallery && $offer->isGallery())<span class="text-accent-text nums">в галерее@if ($offer->interests_count), {{ $offer->interests_count }} {{ \App\Support\Plural::of($offer->interests_count, ['интерес', 'интереса', 'интересов']) }}@endif</span>@endif
            @if ($missing)<span class="text-danger">{{ implode(', ', $missing) }}</span>@endif
            @if ($late)<span class="text-danger nums">страховая до {{ $offer->insurer_deadline_at->translatedFormat('j M') }}</span>@endif
        </span>
    </td>
        @break
        @case('vendor')
    {{-- Вендор и номер убытка одним столбцом, как в «Наличии»: логотип (имя — подсказкой), номер с копированием. --}}
    <td class="{{ $col->classes() }}"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<span @class(['arrive-type' => $busy])><x-ui.copy-code :value="$offer->claim_ref"/></span>@endif</span></td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">@if (\App\Offers\OfferNumber::isPublic($offer))<x-ui.copy-code :value="(string) $offer->number"/>@endif</td>
        @break
        @case('city')
    {{-- Город — именем без номера региона: столбец узкий, регион виден в карточке. --}}
    <td class="{{ $col->classes() }} arrive-late">@if ($busy && ! $offer->settlement)<span class="skeleton arrive-bar w-16"></span>@elseif ($offer->settlement)<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $offer->settlement->name }}</x-ui.place>@endif</td>
        @break
        @case('gap')
    <td class="{{ $col->classes() }}"></td>
        @break
        @case('state')
    <td class="{{ $col->classes() }}">
        {{-- Кто ведёт — аватаром перед этапом (имя — подсказкой): именем строка не влезала в ширину. --}}
        @if ($line)<span class="inline-flex max-w-full items-center gap-1.5 align-middle" @if ($line->who) title="{{ $line->who }}" @endif>@if ($line->person)<x-ui.avatar :user="$line->person" :size="22"/>@endif<span class="{{ $tone }}">{{ $stateWord }}</span></span>
        @elseif ($mixed)<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $stateWord }}</span>
        @elseif ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span>
        @elseif ($slot)<span class="text-accent-text">{{ $slot }}</span>
        {{-- Во вкладке «Опубликованные» «выбрать» говорит заголовок группы — в строке когда закрылся приём. --}}
        @elseif ($pick)<span class="nums {{ $count ? 'text-urgent' : 'text-ink-muted' }}">закрыт {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</span>
        @else<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $offer->crmTab() }}</span>@endif
    </td>
        @break
        @case('bids')
    {{-- «2 до 5 440 000, 1 в гараж». --}}
    <td class="{{ $col->classes() }} {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">@if ($gallery){{ $count ?: '' }}@else{{ $priceBids ?: '' }}@if ($priceBids && $offer->top_bid)<span class="ml-1 text-sm text-ink-muted">до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif{{ $priceBids && $garageBids ? ',' : '' }}@if ($garageBids)<span class="ml-1 whitespace-nowrap">{{ $garageBids }} <span class="text-sm">в гараж</span></span>@endif @endif</td>
        @break
        @case('value')
    <td class="{{ $col->classes() }}">{{ $offer->value && \App\Offers\Pricing::byValue($offer->vendor_id) ? \App\Support\Money::nums($offer->value) : '' }}</td>
        @break
        @case('floor')
    @if ($floorSide)
    {{-- Закупочная на месте цены («Без закупочной цены») — кто завёл и когда стоит при ней, как при цене. --}}
    <td class="{{ $col->classes() }}">@if ($when)<span class="num-meta sm:hidden">{{ \App\Support\Ago::stamp($when) }}@if ($who)<x-ui.avatar :user="$who" :size="16" title="{{ $who->name }}"/>@elseif ($system)<x-ui.system-avatar :via="$offer->created_via" :size="16"/>@endif</span>@endif @if ($busy && $nofloor)<span class="skeleton arrive-bar w-16"></span>@elseif ($nofloor)<x-offer.rate-chip :offer="$offer" label="Заполнить"/>@elseif ($offer->floor_price)<span @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif</td>
    @else
    <td class="{{ $col->classes() }}">@if ($offer->floor_price)<span @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif</td>
    @endif
        @break
        @case('price')
    <td class="{{ $col->classes() }} arrive-late {{ $admin && ($offer->marginMark()['tone'] ?? null) === 'loss' ? 'text-danger' : '' }}">
        {{-- Краткая таблица (телефон, карточка рядом): кто завёл и когда — вплотную слева от цены (10.10.2026, начальник: с
             телефона непонятно, что когда создано и кем; слева всегда номер убытка, под ценой — подтверждения). Сегодня —
             время, иначе дата, за ней аватар. Столбец «Создано» в краткой таблице спрятан. --}}
        @if ($when)<span class="num-meta sm:hidden">{{ \App\Support\Ago::stamp($when) }}@if ($who)<x-ui.avatar :user="$who" :size="16" title="{{ $who->name }}"/>@elseif ($system)<x-ui.system-avatar :via="$offer->created_via" :size="16"/>@endif</span>@endif
        @if ($busy && $rate)<span class="skeleton arrive-bar w-16"></span>
        @elseif ($rate)<x-offer.rate-chip :offer="$offer"/>
        @elseif ($unpriced)
        {{-- Корректировка («Оцененные», 11.10.2026): своя цена менеджера — «620 000 → пилюля», как «закупочная → продажи»;
             согласились — галочка с числом у цены; ждём — аватары и срок под ценой вместо закупочной. --}}
        @elseif ($price->shown() && $correcting)
            @if ($proposes)<span class="text-ink-muted">{{ $price::money($price->to) }}&nbsp;→</span> <x-offer.correction-chip :offer="$offer" part="proposal"/>@else{{ $price::money($price->to) }} <x-offer.correction-chip :offer="$offer" part="agreed"/>@endif
            @if ($waits)<span class="mt-1 flex justify-end sm:mt-0 sm:ml-1.5 sm:inline-flex"><x-offer.correction-chip :offer="$offer" part="waiting"/></span>@endif
        @elseif ($price->shown()){{ $price::money($price->to) }}
        @elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
        {{-- Телефон: подтверждения — под ценой, а не в конце второй строки: там их съедало «…» за номерами (владелец
             04.10.2026: «самое важное, никогда нельзя скрывать»). Есть подтверждения — они вместо закупочной. --}}
        {{-- В минус или почти без наценки (`Offers\Margin`, 14.10.2026) — под ценой, только в CRM админу. --}}
        @if ($admin && ! $busy && ($margin = $offer->marginMark()))<x-offer.margin :m="$margin" class="cell-sub max-sm:!ml-0"/>@endif
        @if ($count)<span class="cell-sub sm:hidden nums {{ $gallery ? '!text-accent-text' : '!text-urgent' }}">{{ $countWord }}</span>
        @elseif ($offer->floor_price && $has('floor') && ! ($correcting && $waits))<span class="cell-sub sm:hidden" @if ($busy) data-arrive-count @endif>{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif
    </td>
        @break
        @case('when')
    {{-- Когда заведено (черновик — когда начали): последним, приглушённо и в одну строку; в карточке справа столбца нет.
         Аватаром перед датой: во вкладке шага — кто перевёл сюда, иначе кто завёл черновик. --}}
    <td class="{{ $col->classes() }}">
        @php $face = ($byTab || $draft) ? $who : null; $robot = ($byTab || $draft) && $system; @endphp
        @if ($face || $robot)<span class="inline-flex items-center gap-2 align-middle">@if ($face)<x-ui.avatar :user="$face" :size="20" title="{{ $face->name }}"/>@else<x-ui.system-avatar :via="$offer->created_via" :size="20"/>@endif @endif{{ $when?->translatedFormat($when->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}@if ($face || $robot)</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
