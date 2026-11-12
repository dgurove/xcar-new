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
    $docs = $selling ? [] : \App\Offers\OfferFiles::docs($offer, \App\Offers\OfferFiles::threads($offer));
    $form = 'detail-edit-'.$n;
@endphp
<x-ui.detail>
    <x-ui.row-card stack :href="'/offers/'.$n" :title="$offer->titleWithYear()" :photos="$offer->visiblePhotos()" :facts="$selling ? array_slice($offer->facts(), 1) : []">
        <x-slot:status><span class="state {{ $statusTone }}">{{ $statusWord }}</span></x-slot:status>
        <x-slot:badge>@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-car.keys :count="$offer->keys_count"/><x-ui.links :offer="$offer"/><x-offer.margin :m="$offer->marginMark()"/></x-slot:badge>
        <x-slot:media>@include('admin.offers.detail-photos')</x-slot:media>
        <x-slot:marks><x-car.codes :offer="$offer"/></x-slot:marks>
        {{-- Строка цены (12.11.2026): слева главное число, справа срок обязывающего — дата и время (начальник). --}}
        @if ($aside || \App\Offers\Binding::matters($offer))
            <x-slot:aside>
                <div class="flex items-start justify-between gap-3">
                    @if ($aside)
                        <div><div class="nums whitespace-nowrap text-lg font-semibold">{{ $aside[0] }}</div>@if ($aside[1])<div class="text-xs text-ink-muted">{{ $aside[1] }}</div>@endif</div>
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
                <div class="contents" data-controller="menu">
                    <button type="button" class="btn btn-s btn-quiet btn-round" data-action="menu#toggle" aria-label="Действия" aria-haspopup="menu" aria-controls="car-actions-{{ $n }}"><x-ui.icon name="more" class="size-[18px]"/></button>
                    <div id="car-actions-{{ $n }}" class="menu" popover data-menu-target="list" data-align="end" role="menu">
                        @include('admin.car.actions', ['mode' => 'menu'])
                    </div>
                </div>
            </x-slot:menu>
        @endif
        <x-slot:actions>
            {{-- Где машина и кто её держит — строками со значком (`admin.car.facts`), а не фразой через запятую (12.11.2026). --}}
            @include('admin.car.facts')
            @include('admin.car.alerts')
            {{-- Ряд пилюль: чат с менеджером сделки (пилюлей, живой), остальные чаты, документы, «Показать менеджеру», ссылка
                 менеджеру на его сделку или машину гаража (03.11.2026, владелец: карточкой посреди окна была непонятной). --}}
            @php $personDeal = $admin ? ($garageCar?->deal ?? ($deal?->isActive() ? $deal : null)) : null; @endphp
            @if ($dealChat || $otherChats->isNotEmpty() || $docs || ($admin && $deal?->isActive()) || $personDeal)
                <div class="flex w-full flex-wrap items-center gap-2">
                    @if ($dealChat)<x-deal.chat-card :deal="$deal" :chat="$car->dealChat()" compact/>@endif
                    @if ($otherChats->isNotEmpty())<x-ui.pill :tone="$chatUnread ? 'urgent' : 'plain'" href="/work/chats?preset=all&q={{ $n }}" data-turbo-frame="_top"><x-ui.icon name="chat" class="size-4"/> {{ $otherChats->count() === 1 ? 'Чат' : 'Чаты' }}@if ($otherChats->count() > 1 && ! $chatUnread) <span class="nums text-ink-dim">{{ $otherChats->count() }}</span>@endif @if ($chatUnread) <span class="badge">{{ $chatUnread }}</span>@endif</x-ui.pill>@endif
                    @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
                    @if ($admin && $deal?->isActive())<x-deal.reply-review :deal="$deal" pill/>@endif
                    @if ($personDeal)<x-deal.person-link :deal="$personDeal" pill/>@endif
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
            @if ($admin)<div class="@container/lanes min-w-0">@include('admin.offers.lanes', ['compact' => true])</div>@endif
            {{-- Письма — все строками, как в «Почте»: по ним заполняют поля и оценивают (11.10.2026). --}}
            @if ($letters)
                <section>
                    <h2 class="detail-section">Письма @if ($dealLetters)<span class="nums font-normal text-ink-dim">{{ $dealLetters }}</span>@endif</h2>
                    <x-mail.letters :messages="$messages" :url="'/offers/'.$n.'/letters'" :asks="$asks" :stages="$stages" split/>
                </section>
            @endif
            {{-- Одна форма окна: «Цены» и поля ТС, пока машина продаётся; «Показ» — тот же модуль, что на странице. --}}
            <form method="post" action="/offers/{{ $n }}" id="{{ $form }}" class="detail-edit flex flex-col gap-5" @if ($admin) data-price-guard="edit" data-price-guard-offer="{{ $n }}" @endif data-controller="vin save-bar" data-save-bar-partial-value="true" data-save-bar-dirty-value="{{ $errors->any() && old('_fields') !== null ? 'true' : 'false' }}" data-save-bar-sent-value="{{ json_encode(array_values((array) old('_fields', []))) }}" data-turbo-frame="detail">
                @csrf @method('put') @if ($errors->has('price_check'))<input type="hidden" name="price_ok" value="1">@endif
                @if ($selling && ! $steps)
                    @include('admin.offers.price-steps', ['plain' => true])
                    @if ($admin)<section>@include('admin.offers.fields.tags')</section>@endif
                @endif
                {{-- «Показ» полями — у продающейся; у сделки и гаража публикация для других — строкой сведений и пунктом «⋯». --}}
                @if ($selling && ! ($steps || $intake || $unsold))<x-offer.showing :offer="$offer" :form="$form" as="section"/>@endif
                @if ($selling)
                    <x-car.blocks :lane="$offer" :blocks="['car', 'papers', 'specs']" as="section"
                        :required="$offer->state === OfferState::Draft ? ['vendor_id', 'claim_ref', 'brand_id'] : []"/>
                    @include('admin.offers.fields.sale', ['as' => 'section'])
                @endif
            </form>
            @php $noteDeal = $garageCar?->deal ?? $deal; @endphp
            @if ($admin && $noteDeal)<x-deal.note :deal="$noteDeal"/>@endif
            @if ($selling)
                <section>
                    <h2 class="detail-section">Документы</h2>
                    @include('admin.offers.papers-block')
                </section>
            @endif
        </div>
        @include('admin.car.sheets')
        <x-slot:foot><x-ui.save-bar :form="$form"/></x-slot:foot>
    </x-ui.row-card>
</x-ui.detail>
