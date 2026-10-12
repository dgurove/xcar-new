{{-- Предложения и галерея: название раздела со счётчиком, тулбар, карточки. Первый экран с числом — на лендинге `/`. --}}
@php
    $user = auth()->user();
    $trail = [['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения']];
    // Выбор пачкой «Открыть покупателям» — только когда есть покупатели в «Выбранных» (12.10.2026).
    $selecting = $user?->isManager() && !$gallery && \App\Offers\Showing::pickable($user);
    // Покупателю та же лента и тот же тулбар: у него нет только первого экрана, а пустая лента — с контактом менеджера.
    $buyer = $user?->isBuyer() ?? false;
    $title = $gallery ? 'Галерея' : 'Предложения';
    // Столбец «Приём» — тем, кто подтверждает ценой, и в галерее (там окно показа).
    $deadline = $gallery || ($user?->bidsOnSite() ?? false);
@endphp
<x-ui.shell :title="$title" :heading="false" :trail="$trail" :detail="$detail">

    <div id="catalog-section" @if ($selecting) data-controller="selection" data-selection-url-value="/buyers/showings/new" @endif>
        <div>
            {{-- Только название раздела: соседние разделы уже в шапке, а на телефоне и его место — лента пилюль тулбара. --}}
            <div class="hidden md:block">
                <x-ui.section-title level="h1" :count="$gallery ? $counts['gallery'] : $counts['offers']">{{ $title }}</x-ui.section-title>
            </div>

            {{-- Лупа сайта — в шапке (поиск по разделам); пришли из неё — тулбар сразу в поиске, с полем и «Отмена». --}}
            <x-ui.toolbar class="md:mt-5" :sort="$sorting" :pills="$views" :dots="$gallery ? [] : ['urgent' => 'urgent', 'fresh' => 'fresh', 'ending' => 'hot']" :pill="$filters['view'] ?? ''" :counts="$counts" :name="$gallery ? 'gallery' : 'catalog'" :facets="$facets" :search="filled($filters['q'] ?? null) ? 'Марка, модель, номер, VIN' : null" search-target="#catalog-list">
                <x-slot:extra>
                    @if ($selecting && $offers->isNotEmpty() && ! \App\Support\ListView::isTable($view))<button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="selection#toggle" data-selection-target="toggle" aria-pressed="false"><x-ui.icon name="check-circle" class="size-4"/><span class="hidden sm:inline">Выбрать</span></button>@endif
                    <x-ui.view-switch :current="$view"/>
                </x-slot:extra>
            </x-ui.toolbar>

            <div class="mt-6" id="catalog-list">
                {{-- Подписка на бот предложений — строкой в начале списка (владелец 03.10.2026), только в каталоге. --}}
                @unless ($gallery)<x-offers-bot.card/>@endunless
                {{-- Оценка менеджером (11.10.2026): что оценить — первой пачкой лентой, с полосой «ваш ход»; что уже оценено и ждёт
                     решения XCar — такой же лентой в самом конце списка (владелец: «Ждут решения» первым рядом и строками не просил). --}}
                @php
                    $toEstimate = $estimates->reject(fn ($o) => $o->openEstimates->first()?->isGiven());
                    $estimated = $estimates->filter(fn ($o) => $o->openEstimates->first()?->isGiven());
                @endphp
                {{-- «Срочно» (12.10.2026, владелец): самой первой лентой, с огоньком и сроком приёма цветом огня. Строками и
                     таблицей — первыми строками с заголовком. --}}
                @if ($urgent->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-urgent" :view="$view" class="mb-6">
                        <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
                        <tr class="table-group"><th colspan="99" scope="colgroup"><x-ui.icon name="fire" fill class="mr-1 inline size-4 align-[-2px] text-fire"/>Срочно <span class="nums">{{ $urgent->count() }}</span></th></tr>
                        @foreach ($urgent as $offer)
                            <x-offer.site-table-row :offer="$offer" :context="$context" :deadline="$deadline"/>
                        @endforeach
                    </x-ui.table>
                    @else
                    <div class="mb-6" data-controller="ticker">
                        <x-ui.shelf title="Срочно" tone="urgent" :count="$urgent->count()" id="catalog-urgent" :data-selection-target="$selecting ? 'list' : null" :data-action="$selecting ? 'click->selection#tap:capture change->selection#change' : null">
                            @foreach ($urgent as $offer)
                                <x-offer.card :offer="$offer" :context="$context"/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                @if ($toEstimate->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-estimates" :view="$view" class="mb-6">
                        <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
                        <tr class="table-group"><th colspan="99" scope="colgroup">Нужно оценить <span class="nums">{{ $toEstimate->count() }}</span></th></tr>
                        @foreach ($toEstimate as $offer)
                            <x-offer.site-table-row :offer="$offer" :deadline="$deadline" :estimate="$offer->openEstimates->first()"/>
                        @endforeach
                    </x-ui.table>
                    @else
                    {{-- Лентой вбок (владелец 11.10.2026: плитки во всю ширину «гигантские»): узкие плитки одной строкой, сколько бы
                         их ни было, на любом виде списка. --}}
                    <div class="mb-6" data-controller="ticker">
                        <x-ui.shelf title="Нужно оценить" :count="$toEstimate->count()" id="catalog-estimates">
                            @foreach ($toEstimate as $offer)
                                <x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()"/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                {{-- «Открыты только Вам» (11.10.2026, владелец): открытое одному этому менеджеру — выше остальных, красной пометкой
                     (новое — голубое, горящее — оранжевое). Плитками — лентой вбок, строками и таблицей — первыми строками с заголовком. --}}
                @if ($exclusive->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-only" :view="$view" class="mb-6">
                        <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
                        <tr class="table-group"><th colspan="99" scope="colgroup"><span class="card-tone card-tone--only"></span>Открыты только Вам <span class="nums">{{ $exclusive->count() }}</span></th></tr>
                        @foreach ($exclusive as $offer)
                            <x-offer.site-table-row :offer="$offer" :context="$context" :deadline="$deadline" only/>
                        @endforeach
                    </x-ui.table>
                    @elseif ($view === \App\Support\ListView::LIST)
                    <div id="catalog-only" class="cards cards--list mb-6" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        <h2 class="cards-group"><span class="card-tone card-tone--only self-center"></span>Открыты только Вам <span class="nums">{{ $exclusive->count() }}</span></h2>
                        @foreach ($exclusive as $offer)
                            <x-offer.card :offer="$offer" :context="$context" only/>
                        @endforeach
                    </div>
                    @else
                    <div class="mb-6" data-controller="ticker">
                        <x-ui.shelf title="Открыты только Вам" tone="only" :count="$exclusive->count()" id="catalog-only" :data-selection-target="$selecting ? 'list' : null" :data-action="$selecting ? 'click->selection#tap:capture change->selection#change' : null">
                            @foreach ($exclusive as $offer)
                                <x-offer.card :offer="$offer" :context="$context" only/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                @if ($offers->isEmpty())
                    {{-- Всё ушло в ленты сверху («Срочно», «Открыты только Вам») — «пусто» не пишем. --}}
                    @if ($urgent->isNotEmpty() || $exclusive->isNotEmpty())
                    @elseif ($buyer && ! $narrowed && ! $gallery)
                        {{-- Покупателю пусто: одна фраза и контакт менеджера строкой. Видит всё (12.10.2026) — значит, у менеджера
                             сейчас ничего нет; видит выбранные — менеджер ещё не открыл. Галерея от менеджера не зависит. --}}
                        <x-ui.empty id="catalog-empty" class="pb-0">{{ $user->buyerSeesAll() ? 'Предложений в продаже сейчас нет' : ($manager ? $manager->shortName().' пока ничего вам не открыл' : 'Вам пока ничего не открыли') }}</x-ui.empty>
                        @if ($manager)<div class="list mx-auto mt-4 max-w-sm"><x-ui.person-row :user="$manager" :size="44" class="row"/></div>@endif
                    @else
                    {{-- «Сбросить» снимает чипы пустыми значениями, иначе ListPrefs вернёт запомненный город; пилюля и поиск — тоже.
                         Без предзагрузки: курсор над ссылкой стирал бы память фильтра. --}}
                    <x-ui.empty :href="$facets->resetUrl(['view' => null, 'q' => null])" :link="$narrowed ? 'Сбросить фильтры' : null" id="catalog-empty" data-turbo-prefetch="false">
                        @if ($narrowed) По этим условиям ничего нет @elseif ($gallery) Пока пусто @else Предложений пока нет @endif
                    </x-ui.empty>
                    @endif
                    @include('site.catalog-estimated')
                @else
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog" :view="$view" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="false">
                        <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
                        @php $group = null; @endphp
                        @foreach ($offers as $offer)
                            @if ($groups && $group !== ($label = \App\Offers\CloseGroups::label($offer, $gallery)))@php $group = $label; @endphp<tr class="table-group"><th colspan="99" scope="colgroup">{{ $label }} <span class="nums">{{ $groupSizes[$label] ?? '' }}</span></th></tr>@endif
                            <x-offer.site-table-row :offer="$offer" :context="$context" :deadline="$deadline"/>
                        @endforeach
                    </x-ui.table>
                    @elseif ($shelves)
                    {{-- По сроку плитками — пачка на ленту вбок (11.10.2026, владелец и начальник: «горизонтальный скролл в разы
                         удобнее»), весь список одной страницей. Выбор и живые правки — на обёртке, плитки ищутся вглубь. --}}
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="false" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        @foreach ($offers->getCollection()->groupBy(fn ($o) => \App\Offers\CloseGroups::label($o, $gallery)) as $label => $pack)
                            <x-ui.shelf :title="$label" :count="$groupSizes[$label] ?? $pack->count()">
                                @foreach ($pack as $offer)
                                    <x-offer.card :offer="$offer" :context="$context"/>
                                @endforeach
                            </x-ui.shelf>
                        @endforeach
                    </div>
                    @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="{{ ! $groups && in_array($sort, ['-published', '-opened'], true) ? 'true' : 'false' }}" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        {{-- По сроку — подписью над каждой пачкой слота («До 17:00 сегодня»), владелец 10.10.2026. --}}
                        @php $group = null; @endphp
                        @foreach ($offers as $offer)
                            @if ($groups && $group !== ($label = \App\Offers\CloseGroups::label($offer, $gallery)))@php $group = $label; @endphp<h2 class="cards-group">{{ $label }} <span class="nums">{{ $groupSizes[$label] ?? '' }}</span></h2>@endif
                            <x-offer.card :offer="$offer" :context="$context"/>
                        @endforeach
                    </div>
                    @endif
                    @include('site.catalog-estimated')
                    @unless ($shelves)<div class="mt-10"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>@endunless
                @endif
            </div>
        </div>
        @if ($selecting && $offers->isNotEmpty())
            {{-- Полоса режима выбора и шторка «Открыть…» с фреймом под отмеченные. --}}
            <div data-controller="sheet" data-action="selection:open@window->sheet#open" class="contents">
                <x-ui.action-bar data-selection-target="bar" hidden>
                    <button type="button" class="btn btn-accent min-w-0 flex-1" data-action="selection#show" data-selection-submit disabled>Открыть <span class="nums" data-selection-target="count"></span></button>
                    <button type="button" class="btn btn-quiet btn-round btn-lg" data-action="selection#cancel" aria-label="Отмена"><x-ui.icon name="x" class="size-6"/></button>
                </x-ui.action-bar>
                <x-ui.sheet id="show-many" title="Открыть покупателям" wide>
                    <turbo-frame id="show-frame" data-selection-target="frame" class="block min-h-40" target="_top">
                        <x-ui.skeleton :rows="3"/>
                    </turbo-frame>
                </x-ui.sheet>
            </div>
        @endif
    </div>
</x-ui.shell>
