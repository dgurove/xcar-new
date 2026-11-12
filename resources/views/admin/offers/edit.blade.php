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
    $carDone = ! $empty && ! $fromMail && $offer->vendor_id && $offer->brand_id && $offer->model_id
        && ($offer->claim_ref || $offer->vendor?->claim_ref_optional);
    $foldCar = $published || $carDone ? true : null;
    // Чат сделки — карточкой первой в правой колонке (`x-deal.chat-card`, 07.10.2026); в «Чатах» и пилюле — остальные.
    $dealChat = $deal?->buyer;
    $otherChats = $car->otherChats();
    $foldMoney = $published && ($offer->floor_price || $offer->asking_price) && ! $errors->hasAny($moneyKeys) && ! array_intersect($hints, $moneyKeys);
    // Черновик (не в слоте и не на аукционе) — «Показ» строкой в карточке «Цены», отдельной карточки нет.
    $draftShow = $admin && ! $garageCar && ! $deal && $offer->state === OfferState::Draft && ! $offer->isScheduled() && ! $offer->onAuction();
@endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title :back="['Предложения', '/offers']" cache="no-cache">
    <x-slot:badge><x-car.keys :count="$offer->keys_count" label/><x-ui.links :offer="$offer"/></x-slot:badge>
    {{-- Под заголовком — только состояние и метки, пилюлями одного роста (номер, убыток и документы — в полях и блоках
         ниже). Метки: выбранные пилюлями, «+» и нажатие на метку — шторка со списком и своей меткой; галки ходят в форму
         оффера через form=, пилюли перерисовывает tag-chips сразу. Срок приёма, чаты и импорт — только когда они есть. --}}
    @php
        $own = $offer->tag_colors ?? [];
        $tagStyle = fn (string $name) => \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own));
    @endphp
    {{-- Коды машины под названием, как в окне (09.11.2026, владелец: «не листать до полей»): номер, убыток или ДЛ, VIN —
         копируются нажатием (`x-car.codes`, одни с окном). --}}
    <div class="-mt-3 mb-3 flex flex-wrap items-center gap-1.5"><x-car.codes :offer="$offer"/></div>
    <div class="offer-head mb-4 flex flex-wrap items-center gap-1.5">
        @if ($garageCar)
            {{-- Отданная в гараж — «в гараже», как в сделке, пока идут и бумаги со страховой (владелец 06.10.2026). --}}
            <x-ui.pill tone="plain" href="#garage">В гараже, {{ $garageCar->manager?->shortName() ?? 'взяли под себя' }}</x-ui.pill>
        @elseif ($admin && $offer->deal)
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
        {{-- Сроки и переписка — строкой текста под капсулами (владелец 10.10.2026: «что-то чипами, что-то текстом»): приём
             ещё сколько или закрыт с какого времени, продление лаймовыми словами, чаты ссылкой. --}}
        @php $chatUnread = $otherChats->sum('unread_for_staff'); @endphp
        {{-- Действия с предложением целиком — справа в строке капсул (19.10.2026, владелец: своей строкой «всё съезжает»). --}}
        @if ($admin)
            <span class="ml-auto flex items-center gap-1">
                @if ($offer->parkVehicle && ! $offer->proposed() && \App\Offers\Actions\UnlistParkOffer::allowed($offer))
                    <form method="post" action="/offers/{{ $n }}/unlist" class="contents" data-turbo-confirm="Снять с продажи? Черновик удалится, ТС останется на парковке">@csrf<button type="submit" class="btn btn-s btn-quiet">Снять с продажи</button></form>
                @endif
                @include('admin.offers.share-button')
            </span>
        @endif
        @if (($admin && ! $intake && $offer->closed()) || $otherChats->isNotEmpty())
            <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                @if (! $admin || $intake)
                @elseif ($offer->closed())
                    <span class="nums text-ink-muted">Приём закрыт с {{ \App\Support\Ago::html($offer->bids_close_at, true, words: false) }}</span>
                @elseif ($offer->bids_close_at && $offer->state === OfferState::Open)
                    <span class="nums">Приём <span data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at, 'ещё') }}</span></span>
                @endif
                @if ($otherChats->isNotEmpty())<a href="/work/chats?preset=all&q={{ $offer->number }}" class="inline-flex items-center gap-1 {{ $chatUnread ? 'text-urgent' : 'text-accent-text' }}"><x-ui.icon name="chat" class="size-4"/>{{ $otherChats->count() === 1 ? 'Чат' : 'Чатов: '.$otherChats->count() }}@if ($chatUnread) <span class="badge">{{ $chatUnread }}</span>@endif</a>@endif
            </div>
        @endif
        @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
    </div>
    {{-- Плашки дел — блоком с отступом, только когда хоть одна есть (`:empty` не срабатывал: от вида остаются переводы строк). --}}
    @php ob_start(); @endphp
    @include('admin.car.alerts')
    @php $alerts = trim(ob_get_clean()); @endphp
    @if ($alerts !== '')<div class="mb-4 flex flex-col gap-4">{!! $alerts !!}</div>@endif
    {{-- Письмо на разбор не ждёт — «Показать менеджеру» руками, пилюлей. --}}
    @if ($admin && $offer->deal && ! \App\Offers\ReplyReview::pending()->where('deal_id', $offer->deal->id)->exists())<div class="mb-4"><x-deal.reply-review :deal="$offer->deal" pill/></div>@endif

    {{-- Раскладка (06.10.2026, владелец): слева «Транспортное средство», под ним дорожки в ряд (`admin.offers.lanes`:
         «Продажа», «Вывоз», «Гараж» / «Сделка» / «Подтверждения») — в основной колонке, правую не занимают (07.10.2026);
         справа чат, «Цены», «Показ», заметка сделки, «Чаты» и «История» (12.10.2026); фото, документы и письма — во всю ширину. На телефоне одной колонкой по order-*: дорожки первыми — в них ход. Колонки — по ширине
         содержимого (@container): с открытой справа шторкой документов редактор в одну колонку. --}}
    <div class="@container">
    <div class="grid grid-cols-1 items-start gap-4 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents @4xl:col-start-1 @4xl:row-start-1 @4xl:flex @4xl:min-w-0 @4xl:flex-col @4xl:gap-4">
        <form method="post" action="/offers/{{ $n }}" id="offer-form" @if (auth()->user()->canManageCrm()) data-price-guard="edit" data-price-guard-offer="{{ $n }}" @endif data-controller="vin draft save-bar next glow empty-blocks{{ $empty ? ' drop-empty' : '' }}" data-glow-fields-value="{{ json_encode($glow) }}" data-save-bar-dirty-value="{{ $errors->any() ? 'true' : 'false' }}" @if ($empty) data-drop-empty-url-value="/offers/{{ $n }}/drop-empty" @endif class="order-2 flex flex-col gap-4 @4xl:order-none">
            @csrf @method('put') @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif

            {{-- Машина — те же блоки, что на парковке (`x-car.blocks`, 19.10.2026: «ТС одна сущность»): «Транспортное
                 средство», «ПТС и СТС», «Характеристики»; продаже своё — «Осмотр». С публикации (и у черновика с заведённой машиной) — «Изменить / Готово»,
                 как в Контактах iOS: текстом, «Изменить» ставит поля, «Готово» сохраняет. Поля всё время в форме, скрытые:
                 сохранение «Показа» шлёт их как есть. Черновик — сразу поля; ошибка раскрывает свой блок, спорные поля —
                 строками в блоке (`x-car.variants`). --}}
            <x-car.blocks :lane="$offer" :blocks="['car', 'papers', 'specs']" :fold="$foldCar" :hints="$hints"
                :required="$published ? [] : ['vendor_id', 'claim_ref', 'brand_id']">
                <x-slot:top>
                    @if ($empty)
                        <div class="mb-3"><x-ui.paste/></div>
                    @endif
                </x-slot:top>
            </x-car.blocks>
            @include('admin.offers.fields.sale', ['fold' => $foldCar])
        </form>

        {{-- Дорожки — от ширины своей колонки (`@container/lanes`): от 52rem три в линию, уже — две, ещё уже — столбиком. --}}
        @if ($admin)
            <div class="@container/lanes order-1 min-w-0 @4xl:order-none">@include('admin.offers.lanes')</div>
        @endif
        </div>

        <div class="contents @4xl:col-start-2 @4xl:row-start-1 @4xl:flex @4xl:flex-col @4xl:gap-4">
            {{-- Чат с менеджером сделки — верхом колонки, на телефоне самым верхом страницы, как на странице сделки на xcar
                 (07.10.2026, владелец: «чуть ли не самая важная кнопка во время сделки»). --}}
            @if ($dealChat)<x-deal.chat-card :deal="$deal" :chat="$car->dealChat()" class="order-first"/>@endif
            {{-- Что сейчас и что с этим сделать — верхом правой колонки, на телефоне сразу под шапкой. --}}
            @if ($intake || $nowKind)
                <div class="order-first flex flex-col gap-4">
                    @if ($intake)@include('admin.offers.intake-block', ['showForm' => 'offer-form', 'showSummary' => $showingSummary])@endif
                    @if ($nowKind)@include('admin.offers.now-block', ['transitions' => $transitions->except(OfferState::Open->value)])@endif
                </div>
            @endif
            {{-- Деньги — справа над «Историей», у всех; поля ходят в форму оффера через form=. На телефоне — сразу под ТС. --}}
            {{-- Цены — так же: суммы текстом, «Изменить» — поля, «Готово» — сохранить (поля привязаны к форме через form=). --}}
            <x-ui.card title="Цены" class="order-3" :data-controller="$published ? 'edit-card' : null" data-edit-card-form-value="offer-form" :data-edit-card-editing-value="$published && ! $foldMoney ? 'true' : null">
                <x-slot:actions><span class="flex items-center gap-3">@include('admin.offers.fields.vat-check', ['form' => 'offer-form'])@if ($published)<button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle">{{ $foldMoney ? 'Изменить' : 'Готово' }}</button>@endif</span></x-slot:actions>
                @if ($published)
                    <div data-edit-card-target="view" @unless ($foldMoney) hidden @endunless>@include('admin.offers.fields.money-view', ['garageOnly' => (bool) $garageView])</div>
                @endif
                <div @if ($published) data-edit-card-target="edit" @if ($foldMoney) hidden @endif @endif>@include('admin.offers.fields.money', ['form' => 'offer-form', 'garageOnly' => (bool) $garageView])</div>
                {{-- Черновик: «Показ» строкой со шторкой и, у оценённого, «Выставить в продажу» и «Отдать» — в этой же карточке. --}}
                @if ($draftShow)
                    <div class="mt-3 flex flex-col gap-2.5">
                        <x-offer.showing-row :offer="$offer" form="offer-form"/>
                        @if ($publishButton)
                            <div class="flex gap-2">
                                <button type="button" class="btn btn-s btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="publish-{{ $n }}:open">Выставить в продажу</button>
                                @if ($giveMenu)<button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="give-{{ $n }}:open">Отдать</button>@endif
                            </div>
                        @endif
                    </div>
                @endif
            </x-ui.card>

            {{-- «Показ» — сразу под «Ценами», на ПК и на телефоне (12.10.2026, владелец): у предложения срок приёма и волны, у
                 сделки — сколько и кому показывать, один модуль `x-offer.showing`; гараж и прочее — без него. Заметка сделки
                 (и гаража — «как сделка») — под ним же, а не в дорожке. --}}
            {{-- «Показ» карточкой — только у сделки; у черновика — строкой в «Ценах», у открытого — в плашке «Приём». --}}
            @unless ($draftShow || $intake)<x-offer.showing :offer="$offer" form="offer-form" :summary="$showingSummary" class="order-3"/>@endunless
            @php $noteDeal = $garageCar?->deal ?? $deal; @endphp
            @if ($admin && $noteDeal)<x-deal.note :deal="$noteDeal" id="deal-note" class="order-3"/>@endif

            @if ($otherChats->isNotEmpty())
            <x-ui.card title="Чаты" class="order-5">
                <div class="flex flex-col divide-y divide-line/40">
                    @foreach ($otherChats as $chat)
                        <a href="/work/chats/{{ $chat->id }}" class="flex items-center gap-3 py-2">
                            <x-ui.avatar :user="$chat->user" :size="36"/>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-2"><span class="truncate {{ $chat->unread_for_staff ? 'font-medium' : '' }}">{{ $chat->displayName() }}</span><span class="ml-auto shrink-0 text-sm text-ink-dim">{{ \App\Support\Ago::html($chat->last_message_at, short: true) }}</span></div>
                                <div class="truncate text-sm text-ink-muted">{{ $chat->lastPreview(auth()->user()) }}</div>
                            </div>
                            @if ($chat->unread_for_staff)<span class="badge">{{ $chat->unread_for_staff }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
            @endif

            {{-- История — последние четыре записи, остальное по «Ещё N»: лента в тридцать строк занимала экран. История машины,
                 а не одной продажи (29.10.2026): с закупки — кто сколько называл, наша цена — до сделки и гаража. --}}
            @php
                // Модератору — без подтверждений, денег, цен продажи и закупки (`OfferEvent::adminOnly`, 15.10.2026).
                $own = auth()->user()->canManageCrm() ? $offer->car->events : $offer->car->events->reject(fn ($e) => $e->adminOnly());
                $events = \App\Cars\LaneHistory::merge($own->take(30), \App\Cars\LaneHistory::forOffer($offer)->take(30))->take(30);
            @endphp
            <x-ui.card title="История" class="order-6"><x-car.history :events="$events"/></x-ui.card>
        </div>

        {{-- Кадры письма прикрепляются после «Завести» (ImportThreadFiles): строка хода и заглушки в ряду, attach_controller
             переспрашивает их, пока строка есть. --}}
        <x-ui.card title="Фотографии" class="order-4 @4xl:order-none @4xl:col-span-2" data-controller="attach" data-attach-url-value="/offers/{{ $n }}/attach" data-attach-car-value="{{ $offer->car_id }}">
            <x-mail.attach-line :model="$offer"/>
            @include('admin.offers.gallery')
        </x-ui.card>

        <x-ui.card title="Документы" class="order-4 @4xl:order-none @4xl:col-span-2">
            @include('admin.offers.papers-block')
        </x-ui.card>

        {{-- Письма — под документами, как в деле ТС: все строками, как в «Почте»; письмо и ответ — окном поверх редактора. --}}
        @if ($letters && auth()->user()->canCrmMail())
            <x-ui.card title="Письма" :count="$dealLetters ?: null" class="order-4 @4xl:order-none @4xl:col-span-2">
                <x-mail.letters :messages="$messages" :url="'/offers/'.$n.'/letters'" :asks="$asks" :stages="$stages" split/>
            </x-ui.card>
        @endif
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
