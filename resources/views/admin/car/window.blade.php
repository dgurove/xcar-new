{{-- Окно машины CRM (фрейм detail) — одно, откуда бы его ни открыли: «Предложения», «Галерея», «Горит», поиск, «Сделки»,
     «Гараж» (11.11.2026, владелец: «единый модуль окна, чтобы изменение вносить в одном месте»; было три окна — предложения,
     сделки и гаража). Данные и решения — `Offers\CarView`, те же, что у страницы `/offers/N`, и те же куски: плашки дел
     (`admin.car.alerts`), «что сейчас» (`price-steps`, `intake-block`, `now-block`), дорожки (`admin.offers.lanes` —
     «Продажа», «Вывоз», «Сделка» / «Гараж» / «Подтверждения»), письма, «Показ», заметка, меню действий (`admin.car.actions`)
     и шторки (`admin.car.sheets`). Окно короче страницы: без «Истории», карточки «Чаты» (здесь — пилюлей) и большой
     галереи; поля ТС и «Цены» — пока машина продаётся (`CarView::selling`), после — на странице.
     Полоса: слово этапа посередине (D3, как на парковке), «Поделиться», «⋯» с действиями, «Открыть страницу», «Закрыть».
     Формы отвечают в окно (`DetailBack`), строку списка окно не рисует — её перечитывает сам список. --}}
@php
    use App\Offers\OfferState;
    [$statusWord, $statusTone] = $car->status();
    $aside = $car->aside();
    $selling = $car->selling();
    $groups = $car->actions($offer->isDeletableBy(auth()->user()));
    $dealChat = $deal?->isActive() && $deal->buyer;
    $otherChats = $car->otherChats();
    $chatUnread = $otherChats->sum('unread_for_staff');
    $form = 'detail-edit-'.$n;
    // Заведённая машина — сеткой ниже хода сделки (`x-car.summary`), правка — шторкой «Данные ТС» (23.11.2026, владелец выбрал
    // из макетов; № убытка и VIN — по-прежнему тегами под названием). Пустой черновик — разделами полей, как было.
    $carAside = $offer->state !== OfferState::Draft || $deal || $garageCar || $offer->carEntered();
@endphp
{{-- Сделка и гараж (22.11.2026, начальник: «пока ты в сделке и сам из неё не вышел — делаешь все операции»): после действия
     окно остаётся на машине, откуда бы его ни открыли (`detail#staying`). --}}
<x-ui.detail :data-detail-stay="! $selling">
    <x-ui.row-card stack :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="$selling && ! $carAside ? array_slice($offer->facts(), 1) : []">
        <x-slot:status><span class="state {{ $statusTone }}">{{ $statusWord }}</span></x-slot:status>
        <x-slot:lead><x-ui.cat-icon :category="$offer->category()"/></x-slot:lead>
        <x-slot:badge><x-car.title-marks :keys="$offer->keys_count" :offer="$offer">@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-offer.margin :m="$offer->marginMark()"/></x-car.title-marks></x-slot:badge>
        <x-slot:media>@include('admin.offers.detail-photos')</x-slot:media>
        {{-- Где машина — мелким текстом рядом с кодами, не плашкой (12.11.2026, владелец: «возле тегов так же мелко, даже не в
             теге, а просто текстом»). На нашей парковке — какой: знак «P» и название (13.11.2026, начальник: «Санкт-Петербург,
             78, [P] Краснопутиловская»). --}}
        @php
            $yard = $offer->car_place === \App\Offers\CarPlace::Ours ? $offer->parkVehicle?->yard : null;
            $where = implode(', ', array_filter([$offer->settlement?->title(), $yard ? null : ($offer->car_place ? ($offer->settlement ? mb_strtolower($offer->car_place->label()) : $offer->car_place->label()) : null)]));
        @endphp
        <x-slot:marks><x-car.codes :offer="$offer"/>@if ($where !== '' || $yard)<span class="detail-place"><x-ui.icon name="map-pin" class="size-4 shrink-0"/>{{ $where }}@if ($yard){{ $where !== '' ? ',' : '' }} <x-park.mark/>{{ $yard->name }}@endif</span>@endif</x-slot:marks>
        {{-- Строка цены (12.11.2026): слева главное число, справа срок обязывающего — дата и время (начальник). --}}
        @if ($aside || \App\Offers\Binding::matters($offer))
            <x-slot:aside>
                <div class="flex items-start justify-between gap-3">
                    @if ($aside)
                        <div><div class="nums whitespace-nowrap text-lg font-semibold">{{ $aside[0] }}</div>@if ($aside[1])<div class="text-sm text-ink-muted">{{ $aside[1] }}</div>@endif</div>
                    @endif
                    <x-offer.binding :offer="$offer" aside class="ml-auto"/>
                </div>
            </x-slot:aside>
        @endif
        <x-slot:tools>
            @include('admin.offers.detail-tools')
        </x-slot:tools>
        {{-- «⋯» — справа в строке названия (12.11.2026, владелец: в полосе тесно, этап в три строки). --}}
        @if ($groups)
            <x-slot:menu>
                {{-- «⋯» пилюлей вровень с названием, действия — шторкой снизу на телефоне и поповером из пилюли на ПК (12.11.2026,
                     владелец выбрал из вариантов: круг 44 px раздвигал строки, меню всплывало поверх фото). --}}
                <x-ui.more :id="'car-actions-'.$n" label="Действия">@include('admin.car.actions', ['mode' => 'menu', 'close' => ''])</x-ui.more>
            </x-slot:menu>
        @endif
        <x-slot:actions>
            {{-- Где машина и кто её держит — строками со значком (`admin.car.facts`), а не фразой через запятую (12.11.2026). --}}
            {{-- Менеджер сделки — плашкой чата (22.11.2026, владелец: «полноценную плашку чата, как на странице сделки»); «Показать
                 менеджеру» и «Ссылка» при нём в окне не рисуются (они — в шагах и на странице); нет менеджера (аукцион) — пилюлями
                 в ряду ниже, как было. --}}
            @php
                $personDeal = $deals ? ($garageCar?->deal ?? ($deal?->isActive() ? $deal : null)) : null;
                $holderActs = $deals && ($car->holder()['user'] ?? null) !== null;
            @endphp
            @include('admin.car.facts')
            @include('admin.car.alerts')
            {{-- Ряд пилюль: остальные чаты; чат с менеджером сделки, «Показать» и ссылка — пилюлями, только когда плашки менеджера
                 нет (иначе чат — плашкой выше, 22.11.2026). --}}
            @php $pillChat = $dealChat && ! $car->holderDeal(); @endphp
            @if ($pillChat || $otherChats->isNotEmpty() || (! $holderActs && (($deals && $deal?->isActive()) || $personDeal)))
                <div class="flex w-full flex-wrap items-center gap-2">
                    @if ($pillChat)<x-deal.chat-card :deal="$deal" :chat="$car->dealChat()" compact/>@endif
                    @if ($otherChats->isNotEmpty())<x-ui.pill :tone="$chatUnread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}" data-turbo-frame="_top"><x-ui.icon name="chat" class="size-4"/> {{ $otherChats->count() === 1 ? 'Чат' : 'Чаты' }}@if ($otherChats->count() > 1 && ! $chatUnread) <span class="nums text-ink-dim">{{ $otherChats->count() }}</span>@endif @if ($chatUnread) <span class="badge">{{ $chatUnread }}</span>@endif</x-ui.pill>@endif
                    @if (! $holderActs && $deals && $deal?->isActive())<x-deal.reply-review :deal="$deal" pill/>@endif
                    @if (! $holderActs && $personDeal)<x-deal.person-link :deal="$personDeal" pill/>@endif
                </div>
            @endif
            {{-- Что сейчас: черновик — «Цены» по шагам (метки — под блоком), открытое — «Приём», слот, аукцион, архив, «Без
                 подтверждений» — плашкой с кнопками; оценённый — ответы менеджеров на корректировку. --}}
            @if ($steps)
                @include('admin.offers.price-steps')
                @if ($admin)<div class="w-full">@include('admin.offers.fields.tags', ['form' => $form])</div>@endif
            @endif
            @if ($intake)@include('admin.offers.intake-block', ['showForm' => $form])@endif
            @if ($nowKind)@include('admin.offers.now-block')@endif
            @if ($correct && ($offer->openEstimates->isNotEmpty() || $offer->declinedEstimates->isNotEmpty()))
                <div class="flex w-full flex-col rounded-(--radius-l) bg-surface-2 p-3">@include('admin.offers.estimates', ['correction' => true])</div>
            @endif
            @if ($errors->has('state'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('state') }}</x-ui.flash>@endif
        </x-slot:actions>

        <div class="mt-4 flex flex-col gap-5">
            {{-- Дорожки — те же, что на странице (`admin.offers.lanes`), но короче (`compact`, 12.11.2026, владелец: «информация по
                 несколько раз дублируется»): путь — текущим шагом, вывоз — без лесенки, деньги — без числа шапки, без ДКП. --}}
            @if ($admin || ($deals && ($deal || $garageCar)))<div class="@container/lanes min-w-0">@include('admin.offers.lanes', ['compact' => true])</div>@endif
            @if ($carAside)<section><x-car.summary :offer="$offer" :sheet="'car-edit-'.$n" as="section" :codes="false" :place="false"/></section>@endif
            {{-- Документы в сделке и гараже — тем же разделом, что у продающейся и на странице (23.11.2026, владелец: «в окне сделки
                 тоже документ надо нормально отображать»; были чипами в шапке без «кто видит»): строка открывает, «⋯» — меню. --}}
            @unless ($selling)@include('admin.offers.papers-block', ['inHead' => true, 'asSection' => true])@endunless
            {{-- Письма и доп. просьбы — тем же куском, что на странице (23.11.2026: «единые модули»): «Написать» и «+ Доп. просьба» словами. --}}
            @php $mailCard = $letters && collect($messages)->isNotEmpty(); @endphp
            @if ($mailCard || $extraRequest)<section class="flex flex-col gap-4">@include('admin.offers.mail-card')</section>@endif
            {{-- Одна форма окна: «Цены» и поля ТС, пока машина продаётся; «Показ» — тот же модуль, что на странице. --}}
            <form method="post" action="/offers/{{ $n }}" id="{{ $form }}" class="detail-edit flex flex-col gap-5" @if ($admin) data-price-guard="edit" data-price-guard-offer="{{ $n }}" @endif data-controller="vin save-bar" data-save-bar-partial-value="true" data-save-bar-dirty-value="{{ $errors->any() && old('_fields') !== null ? 'true' : 'false' }}" data-save-bar-sent-value="{{ json_encode(array_values((array) old('_fields', []))) }}" data-turbo-frame="detail">
                @csrf @method('put') @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
                @if ($selling && ! $steps)
                    @include('admin.offers.price-steps', ['plain' => true])
                {{-- «Показ» — строкой под «Ценами», как на странице (`x-offer.showing-line`, 23.11.2026): у черновика по шагам — своя строка в
                     `price-steps`, у «Без подтверждений» — «Кому» в плашке. --}}
                    @unless ($unsold)<x-offer.showing-line :offer="$offer" :form="$form" :when="! $intake"/>@endunless
                    @if ($admin)<section>@include('admin.offers.fields.tags')</section>@endif
                @endif
                @if ($carAside)
                    @include('admin.offers.car-sheet', ['carForm' => $form, 'carRequired' => $offer->state === OfferState::Draft ? ['vendor_id', 'claim_ref', 'brand_id'] : []])
                @elseif ($selling)
                    <x-car.blocks :lane="$offer" :blocks="['car', 'papers', 'specs']" as="section" :without="($brandTop ?? false) ? ['brand_id'] : []"
                        :required="$offer->state === OfferState::Draft ? ['vendor_id', 'claim_ref', 'brand_id'] : []"/>
                    @include('admin.offers.fields.sale', ['as' => 'section'])
                @endif
            </form>
            @php $noteDeal = $garageCar?->deal ?? $deal; @endphp
            @if ($deals && $noteDeal)<x-deal.note :deal="$noteDeal" folded/>@endif
            @if ($selling)
                {{-- Как на странице: «+ Добавить» лаймом в строке заголовка, под именем — кто видит (23.11.2026). --}}
                @include('admin.offers.papers-block', ['inHead' => true, 'asSection' => true])
            @endif
        </div>
        {{-- Шторки действий — по первому требованию (`OfferController::windowSheets`, `emit_controller`): в окне их до десяти,
             а открывают редко. После ошибки формы шторки рисуются сразу — ошибка и введённое стоят в своей шторке. --}}
        @if ($errors->any() || session()->hasOldInput())
            @include('admin.car.sheets')
        @else
            <div class="contents" data-lazy-sheets="/offers/{{ $n }}/window-sheets{{ ($car->pastDeal ?? false) && $deal ? '?deal='.$deal->id : '' }}"></div>
        @endif
        <x-slot:foot><x-ui.save-bar :form="$form"/></x-slot:foot>
    </x-ui.row-card>
</x-ui.detail>
