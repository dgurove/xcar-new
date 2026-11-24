@php
    use App\Offers\{OfferState, BidState};
    // Решения — `CarView` (одни с окном машины): переходы, «Отдать», «Вернуть в продажу», плашки «что сейчас», дорожки.
    // Здесь — только то, что нужно раскладке страницы.
    // С публикации поля ТС и цены свёрнуты (владелец 06.10.2026); раскрыты сами, если в них есть что смотреть.
    $moneyKeys = ['value', 'floor_price', 'publish_price', 'asking_price', 'min_bid_price', 'min_bid_share'];
    // Подсветка Мигторга читается один раз (`glowed` забирает её из кэша) — и для формы ниже.
    $glow = \App\Offers\Jobs\ImportMigtorgLot::glowed($offer);
    $hints = $glow;
    // Сворачивание и «Показ» — от дела, а не от одного состояния: у машины в сделке или гараже ТС и цены свёрнуты всегда
    // (07.10.2026, владелец: «гараж — это тоже как сделка»).
    $published = $offer->state !== OfferState::Draft || $deal || $garageCar;
    // Черновик с заведённой машиной (вендор, номер убытка, марка, модель) — тоже текстом с «Изменить» (21.10.2026, владелец:
    // «всегда полями, а хочу текстом, когда машина уже заполнена»). Полями — пустой черновик и черновик из писем, пока
    // читалка вписывает поля (`$fromMail`): свёрнутый текст не видел бы вписанного.
    $carDone = ! $empty && ! $fromMail && $offer->carEntered();
    $foldCar = $published || $carDone ? true : null;
    // Заведённая машина — сводкой под фото справа, правка — шторкой «Данные ТС» (23.11.2026, владелец: «в сделке в 99 % не
    // трогают детали машины»); пустой черновик и черновик из писем — полями слева: там их и вводят.
    $carAside = $foldCar === true;
    // Чат сделки — карточкой первой в правой колонке (`x-deal.chat-card`, 07.10.2026); в «Чатах» и пилюле — остальные.
    $dealChat = $deal?->buyer;
    $otherChats = $car->otherChats();
    $foldMoney = $published && ($offer->floor_price || $offer->asking_price) && ! $errors->hasAny($moneyKeys) && ! array_intersect($hints, $moneyKeys);
    // Черновик (не в слоте и не на аукционе) — «Показ» строкой в карточке «Цены», отдельной карточки нет.
    $draftShow = $admin && ! $garageCar && ! $deal && $offer->state === OfferState::Draft && ! $offer->isScheduled() && ! $offer->onAuction();
@endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title :back="['Предложения', '/offers']" cache="no-cache">
    <x-slot:lead><x-ui.cat-icon :category="$offer->category()"/></x-slot:lead>
    <x-slot:badge><x-car.title-marks :keys="$offer->keys_count" keys-label :offer="$offer"/></x-slot:badge>
    {{-- Под заголовком — только состояние и метки, пилюлями одного роста (номер, убыток и документы — в полях и блоках
         ниже). Метки: выбранные пилюлями, «+» и нажатие на метку — шторка со списком и своей меткой; галки ходят в форму
         оффера через form=, пилюли перерисовывает tag-chips сразу. Срок приёма, чаты и импорт — только когда они есть. --}}
    @php
        $own = $offer->tag_colors ?? [];
        $tagStyle = fn (string $name) => \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own));
    @endphp
    {{-- Шапка — две строки (16.11.2026, владелец: «что с вёрсткой??? столько места экрана тупо впустую» — было пять рядов и ~300 px
         до первой карточки): справа от названия — «Снять с продажи» и «Поделиться» (`actions`), под ним одной строкой (`sub`)
         коды (`x-car.codes`, как в окне), состояние, метки и приём. --}}
    @if ($admin)
        <x-slot:actions class="!w-auto ml-auto max-md:!order-1">
                @if ($offer->parkVehicle && ! $offer->proposed() && \App\Offers\Actions\UnlistParkOffer::allowed($offer))
                    <form method="post" action="/offers/{{ $n }}/unlist" class="contents" data-turbo-confirm="Снять с продажи? Черновик удалится, ТС останется на парковке">@csrf<button type="submit" class="btn btn-s btn-quiet">Снять с продажи</button></form>
                @endif
                @include('admin.offers.share-button')
        </x-slot:actions>
    @endif
    <x-slot:sub class="offer-head flex flex-wrap items-center gap-1.5">
        {{-- Коды — в сводке машины справа (`x-car.summary`), когда она есть. --}}
        @unless ($carAside)<x-car.codes :offer="$offer"/>@endunless
        @if ($garageCar)
            {{-- Отданная в гараж — «в гараже», как в сделке, пока идут и бумаги со страховой (владелец 06.10.2026). --}}
            <x-ui.pill tone="plain" href="#garage">В гараже, {{ $garageCar->manager?->shortName() ?? 'взяли под себя' }}</x-ui.pill>
        @elseif ($deals && $offer->deal)
            {{-- В сделке пилюля состояния ведёт к дорожке «Сделка» ниже. --}}
            <x-ui.pill :tone="$offer->state->tone()" href="#deal">{{ $offer->crmTab() }}</x-ui.pill>
        @else
            <x-ui.pill :tone="$offer->state->tone()">{{ $offer->crmTab() }}</x-ui.pill>
        @endif
        <x-offer.margin :m="$offer->marginMark()" class="!ml-0"/>
        {{-- Срок обязывающего предложения — чипом «⏱ 14.11 (2д 5ч)» с окошком, как в окне (12.11.2026, владелец: мигающая красная
             капсула и слово «обязывающее» — «агрессивно, нейрослоп»). --}}
        <x-offer.binding :offer="$offer" aside/>
        {{-- Срочное (12.10.2026) — капсулой огня со сроком приёма. --}}
        @if ($offer->isUrgent())
            <span class="pill pill-fire"><x-ui.icon name="fire" fill class="size-4"/>Срочно, до {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
        @endif
        {{-- «По истечению срока на аукцион» (13.10.2026) — капсулой с концом приёма. --}}
        @if ($offer->auctionPlanned())
            <span class="pill pill-plain"><x-ui.icon name="flag" class="size-4"/>На аукцион после {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
        @endif
        {{-- ТС ещё в «Заявках на приём» парковки (13.10.2026) — капсулой с парковкой, если она известна. --}}
        @if ($admin && $offer->awaitingPark())
            <span class="pill pill-plain"><x-ui.icon name="clock" class="size-4"/>Ждём на парковке{{ $offer->parkVehicle->yard ? ', '.$offer->parkVehicle->yard->name : '' }}</span>
        @endif
        @if ($admin)
            <div class="contents" data-controller="sheet tag-chips" data-action="change->tag-chips#render">
                <span class="contents" data-tag-chips-target="chips">
                    @foreach ($offer->tags ?? [] as $name)<button type="button" class="pill pill-tag" style="{{ $tagStyle($name) }}" data-action="sheet#open">{{ $name }}</button>@endforeach
                </span>
                <button type="button" class="pill pill-plain" data-action="sheet#open" aria-label="Метки"><x-ui.icon name="plus" class="size-4"/><span data-tag-chips-target="word" @if ($offer->tags) hidden @endif>Метка</span></button>
                <x-ui.sheet id="offer-tags" title="Метки">
                    <div class="flex flex-col gap-4">
                        @include('admin.offers.fields.tags', ['form' => 'offer-form', 'label' => false])
                        <x-ui.button type="button" block data-action="sheet#close">Готово</x-ui.button>
                    </div>
                </x-ui.sheet>
            </div>
        @endif
        {{-- Приём — текстом в той же строке (владелец 10.10.2026: «что-то чипами, что-то текстом»). Чаты — карточками справа под
             чатом сделки, «Показать менеджеру» — в ходе сделки (16.11.2026, владелец). --}}
        @if ($admin && ! $intake && $offer->closed())
            <span class="contents text-sm">
                @if (! $admin || $intake)
                @elseif ($offer->closed())
                    <span class="nums text-ink-muted">Приём закрыт с {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
                @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
                    <span class="nums">Приём <span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at, 'ещё') }}</span></span>
                @endif
            </span>
        @endif
    </x-slot:sub>
    @if ($errors->has('state'))<x-ui.flash tone="danger" class="mb-4">{{ $errors->first('state') }}</x-ui.flash>@endif
    {{-- Плашки дел — блоком с отступом, только когда хоть одна есть (`:empty` не срабатывал: от вида остаются переводы строк). --}}
    @php ob_start(); @endphp
    @include('admin.car.alerts')
    @php $alerts = trim(ob_get_clean()); @endphp
    @if ($alerts !== '')<div class="mb-4 flex flex-col gap-4">{!! $alerts !!}</div>@endif

    {{-- Раскладка (23.11.2026, владелец выбрал из макетов): слева письма с доп. просьбами одной карточкой, под ними дорожки
         (`admin.offers.lanes`: «Продажа», «Вывоз», «Гараж» / «Подтверждения»; у сделки — ход с вывозом под ним и «Продажа» справа, её
         «Подтверждения» — справа над «Историей»), у пустого черновика — поля ТС; справа
         чаты и «что сейчас», «Цены», «Показ», заметка сделки, фото со сводкой ТС, «Документы» строками, «История». На телефоне
         одной колонкой по order-* тем же порядком. Колонки — по ширине
         содержимого (@container): с открытой справа шторкой документов редактор в одну колонку. --}}
    <div class="@container">
    <div class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents @4xl:col-start-1 @4xl:row-start-1 @4xl:flex @4xl:min-w-0 @4xl:flex-col @4xl:gap-4">
        {{-- Письма и доп. просьбы поставщику — одной карточкой над ходом сделки (23.11.2026, владелец: «письма с доп. просьбой я бы
             объединил и поместил над блоками с ходами сделки»; были двумя карточками во всю ширину внизу). Письма — строками, как в
             «Почте», письмо и ответ — окном поверх страницы; под ними просьбы (`x-offer.extra-request`) и «Сделать доп. просьбу». --}}
        @php $mailCard = $letters && auth()->user()->canCrmMail() && collect($messages)->isNotEmpty(); @endphp
        @if ($mailCard || $extraRequest)
            <x-ui.card class="order-1 flex flex-col gap-4 @4xl:order-none">
                @include('admin.offers.mail-card')
            </x-ui.card>
        @endif
        <form method="post" action="/offers/{{ $n }}" id="offer-form" @if (auth()->user()->canManageCrm()) data-price-guard="edit" data-price-guard-offer="{{ $n }}" @endif data-controller="vin draft save-bar next glow empty-blocks{{ $empty ? ' drop-empty' : '' }}" data-glow-fields-value="{{ json_encode($glow) }}" data-save-bar-dirty-value="{{ $errors->any() ? 'true' : 'false' }}" @if ($empty) data-drop-empty-url-value="/offers/{{ $n }}/drop-empty" @endif @class(['contents' => $carAside, 'order-3 flex flex-col gap-4 @4xl:order-none' => ! $carAside])>
            @csrf @method('put') @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif

            {{-- Машина — те же блоки, что на парковке (`x-car.blocks`, 19.10.2026: «ТС одна сущность»): «Транспортное
                 средство», «ПТС и СТС», «Характеристики»; продаже своё — «Осмотр». С публикации (и у черновика с заведённой машиной) — «Изменить / Готово»,
                 как в Контактах iOS: текстом, «Изменить» ставит поля, «Готово» сохраняет. Поля всё время в форме, скрытые:
                 сохранение «Показа» шлёт их как есть. Черновик — сразу поля; ошибка раскрывает свой блок, спорные поля —
                 строками в блоке (`x-car.variants`). --}}
            @if ($carAside)
                {{-- Заведённая машина — полями в шторке «Данные ТС» (она внутри формы), на странице — сводкой справа. --}}
                @include('admin.offers.car-sheet')
            @else
                <x-car.blocks :lane="$offer" :blocks="['car', 'papers', 'specs']" :fold="$foldCar" :hints="$hints"
                    :required="$published ? [] : ['vendor_id', 'claim_ref', 'brand_id']">
                    <x-slot:top>
                        @if ($empty)
                            <div class="mb-3"><x-ui.paste/></div>
                        @endif
                    </x-slot:top>
                </x-car.blocks>
                @include('admin.offers.fields.sale', ['fold' => $foldCar])
            @endif
        </form>

        {{-- Дорожки — от ширины своей колонки (`@container/lanes`): от 52rem три в линию, уже — две, ещё уже — столбиком. --}}
        {{-- Модератору — у машины в сделке или гараже (17.11.2026: вся работа со сделкой); у черновика дорожек у него нет, как было. --}}
        @if ($admin || ($deals && ($deal || $garageCar)))
            <div class="@container/lanes order-2 min-w-0 @4xl:order-none">@include('admin.offers.lanes')</div>
        @endif
        </div>

        <div class="contents @4xl:col-start-2 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            {{-- Чат с менеджером сделки — верхом колонки, на телефоне самым верхом страницы, как на странице сделки на xcar
                 (07.10.2026, владелец: «чуть ли не самая важная кнопка во время сделки»). --}}
            {{-- Ссылка менеджеру на его сделку или гараж — в «Поделиться» шапки, только скопировать (23.11.2026, владелец: карточка лишняя). --}}
            {{-- Остальные чаты машины — той же карточкой под чатом сделки (16.11.2026, владелец: «„Чатов: 2“ убрать, лучше бы все чаты
                 отображал, как чат с менеджером, под него»); карточки «Чаты» ниже больше нет. --}}
            {{-- «Подтверждения» сделки — справа прямо над «Историей» (23.11.2026, владелец: сначала «справа сверху над чатом», в тот же
                 день — «не сверху, а прямо над блоком с историей»; `order-6` как у «Истории», выше неё по порядку разметки); дорожки
                 слева — ход сделки с вывозом и «Продажа» (`admin.offers.lanes`). --}}
            @if (($admin || $deals) && $car->lanes()['third'] === 'deal')
                <div class="order-6 flex flex-col gap-4">
                    <x-ui.card title="Подтверждения" id="deal" class="scroll-mt-24">
                        <x-slot:actions><x-offer.bid-for :offer="$offer" head/></x-slot:actions>
                        @include('admin.offers.bids', ['inside' => true, 'compact' => false, 'addHead' => true])
                    </x-ui.card>
                    @if ($offer->interests->isNotEmpty())@include('admin.offers.interests')@endif
                </div>
            @endif
            @if ($dealChat || $otherChats->isNotEmpty())
                <div class="order-first flex flex-col gap-4">
                    @if ($dealChat)<x-deal.chat-card :deal="$deal" :chat="$car->dealChat()"/>@endif
                    @foreach ($otherChats as $chat)
                        <a href="/work/chats/{{ $chat->id }}" class="box deal-chat" aria-label="Чат с {{ $chat->displayName() }}">
                            <x-chat.card-body :chat="$chat" :title="$chat->displayName()" :unread="(int) $chat->unread_for_staff" :user="$chat->user"/>
                        </a>
                    @endforeach
                </div>
            @endif
            {{-- Что сейчас и что с этим сделать — верхом правой колонки, на телефоне сразу под шапкой. --}}
            @if ($intake || $nowKind)
                <div class="order-first flex flex-col gap-4">
                    @if ($intake)@include('admin.offers.intake-block', ['showForm' => 'offer-form', 'showSummary' => $showingSummary])@endif
                    @if ($nowKind)@include('admin.offers.now-block', ['transitions' => $transitions->except(OfferState::Open->value)])@endif
                </div>
            @endif
            {{-- Фото пачками и сводка ТС (`x-car.summary`) — справа под «Ценами» (23.11.2026, владелец: «цены над блоком с фотками»).
                 Без заголовка «Фотографии»: подписи пачек и так «От страховой …». Кадры письма прикрепляются после «Завести»
                 (ImportThreadFiles): строка хода и заглушки в пачке, attach_controller переспрашивает их, пока строка есть. --}}
            <x-ui.card class="order-4 !pb-3 @4xl:order-2" data-controller="attach" data-attach-url-value="/offers/{{ $n }}/attach" data-attach-car-value="{{ $offer->car_id }}">
                <x-mail.attach-line :model="$offer"/>
                @include('admin.offers.gallery')
                @if ($carAside)<x-car.summary :offer="$offer" :sheet="'car-edit-'.$n"/>@endif
            </x-ui.card>
            {{-- Деньги — справа над «Историей», у всех; поля ходят в форму оффера через form=. На телефоне — сразу под ТС. --}}
            {{-- Цены — так же: суммы текстом, «Изменить» — поля, «Готово» — сохранить (поля привязаны к форме через form=). --}}
            <x-ui.card class="order-3 @4xl:order-1" :data-controller="$published ? 'edit-card' : null" data-edit-card-form-value="offer-form" :data-edit-card-editing-value="$published && ! $foldMoney ? 'true' : null">
                {{-- НДС — словами в заголовке: «Цены с НДС 5 %», «Цены указать НДС» (`fields/vat-check`). --}}
                <x-slot:title><span class="inline-flex flex-wrap items-baseline gap-x-1">Цены @include('admin.offers.fields.vat-check', ['form' => 'offer-form'])</span></x-slot:title>
                @if ($published)
                <x-slot:actions><button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $foldMoney ? 'Изменить' : 'Готово' }}</button></x-slot:actions>
                @endif
                @if ($published)
                    <div data-edit-card-target="view" @unless ($foldMoney) hidden @endunless>@include('admin.offers.fields.money-view', ['garageOnly' => (bool) $garageView])</div>
                @endif
                @include('admin.offers.revived')
                <div @if ($published) data-edit-card-target="edit" @if ($foldMoney) hidden @endif @endif>@include('admin.offers.fields.money', ['form' => 'offer-form', 'garageOnly' => (bool) $garageView])</div>
                {{-- «Показ» — последней строкой «Цен» в любом состоянии (23.11.2026, владелец: «иногда показ объединён с блоком цен, иногда
                     нет — показ в любом случае объединять»): `x-offer.showing-line` — показ предложения или публикация сделки для других; у
                     идущего приёма без срока (он в заголовке плашки «Приём»). У черновика ниже — «Выставить в продажу» и «Отдать». --}}
                @if (auth()->user()->canManageCrm())
                <div class="mt-3 flex flex-col gap-2">
                    <x-offer.showing-line :offer="$offer" form="offer-form" :summary="$showingSummary" :when="! $intake"/>
                    @if ($draftShow && $publishButton)
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-s btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="publish-{{ $n }}:open">Выставить в продажу</button>
                            @if ($giveMenu)<button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="give-{{ $n }}:open">Отдать</button>@endif
                        </div>
                    @endif
                </div>
                @endif
                {{-- Оценки менеджеров — последними, как в окне (`price-steps`, корректировка — `admin/car/window`): с 11.10 жили только
                     в окне, на странице их не было вовсе (24.11.2026, владелец: «где тут вообще оценки менеджеров»). --}}
                @if (($rate || $correct) && ($offer->openEstimates->isNotEmpty() || $offer->declinedEstimates->isNotEmpty()))
                    <div @class(['mt-4' => $correct])>@include('admin.offers.estimates', ['correction' => $correct])</div>
                @endif
            </x-ui.card>
            {{-- Заметка сделки — своим блоком под «Ценами» (23.11.2026, владелец: «заметка отдельным блоком»). --}}
            @php $noteDeal = $garageCar?->deal ?? $deal; @endphp
            @if ($deals && $noteDeal)<x-deal.note :deal="$noteDeal" id="deal-note" class="order-3 @4xl:order-1"/>@endif



            {{-- История — последние четыре записи, остальное по «Ещё N»: лента в тридцать строк занимала экран. История машины,
                 а не одной продажи (29.10.2026): с закупки — кто сколько называл, наша цена — до сделки и гаража. --}}
            @php
                // Модератору — без подтверждений, денег, цен продажи и закупки (`OfferEvent::adminOnly`, 15.10.2026).
                $own = auth()->user()->canManageCrm() ? $offer->car->events : $offer->car->events->reject(fn ($e) => $e->adminOnly());
                $events = \App\Cars\LaneHistory::merge($own->take(30), \App\Cars\LaneHistory::forOffer($offer)->take(30))->take(30);
            @endphp
            {{-- Документы — строками справа над «Историей» (23.11.2026, владелец: «компактнее, в боковое меню»; были во всю ширину внизу). --}}
            @include('admin.offers.papers-block', ['inHead' => true, 'cardClass' => 'order-5'])
            <x-ui.card title="История" class="order-6"><x-car.history :events="$events"/></x-ui.card>
        </div>

    </div>
    </div>

    {{-- Черновик из писем до первого сохранения: «Отменить» (черновика не было, цепочка снова в «Из писем»), «Не заявка»
         (и цепочку в архив), «Сохранить». Черновик и дальше только «Сохранить»: публикуют из списка по маршруту. --}}
    @php $draft = $offer->state === OfferState::Draft; @endphp
    <x-mail.window :url="$window" :title="$offer->titleWithYear()"/>
    @include('admin.car.sheets')
    @php $deletable = ! $fromMail && $offer->isDeletableBy(auth()->user()); @endphp
    <x-ui.action-bar>
        @if ($fromMail)
            <form method="post" action="/offers/{{ $n }}/drop" class="contents">@csrf<x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Отменить</x-ui.button></form>
            <form method="post" action="/offers/{{ $n }}/drop" class="contents" data-turbo-confirm="Не заявка? Цепочка уйдёт в архив">@csrf<input type="hidden" name="decline" value="1"><x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Не заявка</x-ui.button></form>
            <x-ui.button form="offer-form" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @elseif ($fromLegacy)
            {{-- Только что «Завести» из таблицы Кати: передумали — черновика нет, номер снова строкой «Не заведены». --}}
            <form method="post" action="/offers/{{ $n }}/legacy-drop" class="contents" data-turbo-confirm="Отменить? Черновик удалится, номер останется в списке" data-turbo-confirm-label="Отменить">@csrf<x-ui.button variant="ghost" class="shrink-0 px-3 sm:px-7">Отменить</x-ui.button></form>
            <x-ui.button form="offer-form" name="then" value="close" class="min-w-0 flex-1">Сохранить</x-ui.button>
        @elseif ($draft)
            {{-- Черновик заводят пачкой подряд — и модератор, и админ (владелец 04.10.2026): «Сохранить» сохраняет и закрывает
                 редактор (в список), «+ Новый» сохраняет и открывает следующий черновик с тем же вендором. Публикуют из
                 списка («Оцененные» → «Выставить в продажу»). Модератор свой черновик удаляет корзиной, админ — в «···». --}}
            @if ($deletable && ! $admin)
                <form method="post" action="/offers/{{ $n }}" class="draft-delete contents" data-turbo-confirm="Удалить черновик? Фото и документы удалятся вместе с ним">@csrf @method('delete')<x-ui.button variant="secondary" round class="btn-lg shrink-0" aria-label="Удалить черновик"><x-ui.icon name="trash" class="size-5"/></x-ui.button></form>
            @endif
            <x-ui.button form="offer-form" name="then" value="close" variant="secondary" class="min-w-0 flex-1">Сохранить</x-ui.button>
            <x-ui.button form="offer-form" name="then" value="next" class="min-w-0 flex-1"><x-ui.icon name="plus" class="size-5"/>Новый</x-ui.button>
        @else
            <x-ui.button form="offer-form" class="min-w-0 flex-1" data-save-bar-button data-save-bar="offer-form" hidden>Сохранить изменения</x-ui.button>
        @endif
        {{-- «···» — те же пункты, что «⋯» окна (`CarView::actions`), кнопками шторки. --}}
        @php $groups = $fromMail || $fromLegacy ? [] : $car->actions($deletable && $admin); @endphp
        @if ($groups)
            {{-- Контроллер шторки — только вместе с ней: у сделки «···» нет, и пустой контроллер падал в консоль. --}}
            <div class="contents" data-controller="sheet">
            <x-ui.button type="button" variant="secondary" round class="btn-lg" data-action="sheet#open" aria-label="Действия"><x-ui.icon name="more" class="size-6"/></x-ui.button>
            {{-- Та же шторка действий, что у «⋯» окна (`x-ui.more`, 12.11.2026): кнопка — большая, рядом с главной в плашке. --}}
            <x-ui.sheet id="offer-actions" anchor bare class="sheet-actions">
                <div class="sheet-actions-list" data-action="click->sheet#item">@include('admin.car.actions', ['mode' => 'menu', 'close' => ''])</div>
                <button type="button" class="sheet-actions-cancel" data-action="sheet#close">Отмена</button>
            </x-ui.sheet>
            </div>
        @endif
    </x-ui.action-bar>
</x-ui.shell>
