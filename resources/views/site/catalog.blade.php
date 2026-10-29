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
    // Столбцы всех таблиц экрана — одно описание (шапки, строки, заголовки пачек).
    $columns = \App\Http\Site\CatalogController::table($deadline, $sorting ?? null, mine: ! $gallery && ($user?->bidsOnSite() ?? false));
@endphp
<x-ui.shell :title="$title" :heading="false" :trail="$trail" :detail="$detail">

    <div id="catalog-section" @if ($selecting) data-controller="selection" data-selection-url-value="/buyers/showings/new" @endif>
        <div>
            {{-- Только название раздела: соседние разделы уже в шапке, а на телефоне и его место — лента пилюль тулбара. --}}
            <div class="hidden md:block">
                <x-ui.section-title level="h1" :count="$gallery ? $counts['gallery'] : $counts['offers']">{{ $title }}</x-ui.section-title>
            </div>

            {{-- Лупа сайта — в шапке (поиск по разделам); пришли из неё — тулбар сразу в поиске, с полем и «Отмена».
                 Раздел пуст целиком (покупателю ещё ничего не открыли) — сортировать и менять вид нечего (19.10.2026). --}}
            @php $none = ! ($gallery ? $counts['gallery'] : $counts['offers']) && blank($filters['q'] ?? null) && ! $allSeen; @endphp
            <x-ui.toolbar class="md:mt-5" :sort="$none ? null : $sorting" :pills="$views" :dots="$gallery ? [] : ['urgent' => 'urgent', 'fresh' => 'fresh', 'ending' => 'hot']" :pill="$filters['view'] ?? ''" :counts="$counts" :name="$gallery ? 'gallery' : 'catalog'" :facets="$facets" :search="filled($filters['q'] ?? null) ? 'Марка, модель, номер, VIN' : null" search-target="#catalog-list">
                <x-slot:extra>
                    @if ($selecting && $offers->isNotEmpty() && ! \App\Support\ListView::isTable($view))<button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="selection#toggle" data-selection-target="toggle" aria-pressed="false"><x-ui.icon name="check-circle" class="size-4"/><span class="hidden sm:inline">Выбрать</span></button>@endif
                    @unless ($none)<x-ui.view-switch :current="$view" :views="\App\Support\ListView::SHELVED"/>@endunless
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
                @if ($view === \App\Support\ListView::GRID)
                    {{-- «Плитками» (15.10.2026, начальник): одна сетка без подписей пачек и лент вбок, со страницами. На первой
                         странице — срочные, «Нужно оценить» и «Открыты только Вам» первыми, «Ждут решения» — в конце: их узнают по
                         метке на плитке. Обёртки `contents` держат id прежних лент. --}}
                    @if ($offers->isEmpty() && $urgent->isEmpty() && $exclusive->isEmpty() && $estimates->isEmpty())
                        @include('site.catalog-empty')
                    @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="{{ ! $groups && $sort === '-published' ? 'true' : 'false' }}" class="cards cards--grid" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        @if ($urgent->isNotEmpty())<div id="catalog-urgent" class="contents">@foreach ($urgent as $offer)<x-offer.card :offer="$offer" :context="$context"/>@endforeach</div>@endif
                        @if ($toEstimate->isNotEmpty())<div id="catalog-estimates" class="contents">@foreach ($toEstimate as $offer)<x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()"/>@endforeach</div>@endif
                        @if ($exclusive->isNotEmpty())<div id="catalog-only" class="contents">@foreach ($exclusive as $offer)<x-offer.card :offer="$offer" :context="$context" only/>@endforeach</div>@endif
                        @foreach ($offers as $offer)
                            <x-offer.card :offer="$offer" :context="$context"/>
                        @endforeach
                        @if ($estimated->isNotEmpty())<div id="catalog-estimated" class="contents">@foreach ($estimated as $offer)<x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()"/>@endforeach</div>@endif
                    </div>
                    <div class="mt-10"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>
                    @endif
                @else
                {{-- «Срочно» (12.10.2026, владелец): самой первой лентой, с огоньком и сроком приёма цветом огня. Строками и
                     таблицей — первыми строками с заголовком. --}}
                @if ($urgent->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-urgent" :view="$view" class="mb-6" :table="$columns">
                        <x-slot:head><x-table.head :table="$columns" :sort="$sorting"/></x-slot:head>
                        <x-table.group :table="$columns" key="urgent" title="Срочно" :count="$urgent->count()"><x-slot:before><x-ui.icon name="fire" fill class="mr-1 inline size-4 align-[-2px] text-fire"/></x-slot:before></x-table.group>
                        @foreach ($urgent as $offer)
                            <x-offer.site-table-row :offer="$offer" :context="$context" :table="$columns"/>
                        @endforeach
                    </x-ui.table>
                    @else
                    <div class="mb-6" data-controller="ticker">
                        <x-ui.shelf title="Срочно" tone="urgent" :count="$urgent->count()" id="catalog-urgent" :data-selection-target="$selecting ? 'list' : null" :data-action="$selecting ? 'click->selection#tap:capture change->selection#change' : null">
                            @foreach ($urgent as $offer)
                                <x-offer.card :offer="$offer" :context="$context" cover/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                @if ($toEstimate->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-estimates" :view="$view" class="mb-6" :table="$columns">
                        <x-slot:head><x-table.head :table="$columns" :sort="$sorting"/></x-slot:head>
                        <x-table.group :table="$columns" key="estimate" title="Нужно оценить" :count="$toEstimate->count()"/>
                        @foreach ($toEstimate as $offer)
                            <x-offer.site-table-row :offer="$offer" :table="$columns" :estimate="$offer->openEstimates->first()"/>
                        @endforeach
                    </x-ui.table>
                    @else
                    {{-- Лентой вбок (владелец 11.10.2026: плитки во всю ширину «гигантские»): узкие плитки одной строкой, сколько бы
                         их ни было, на любом виде списка. --}}
                    <div class="mb-6" data-controller="ticker">
                        <x-ui.shelf title="Нужно оценить" :count="$toEstimate->count()" id="catalog-estimates">
                            @foreach ($toEstimate as $offer)
                                <x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()" cover/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                {{-- «Открыты только Вам» (11.10.2026, владелец): открытое одному этому менеджеру — выше остальных, красной пометкой
                     (новое — голубое, горящее — оранжевое). Плитками — лентой вбок, строками и таблицей — первыми строками с заголовком. --}}
                @if ($exclusive->isNotEmpty())
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog-only" :view="$view" class="mb-6" :table="$columns">
                        <x-slot:head><x-table.head :table="$columns" :sort="$sorting"/></x-slot:head>
                        <x-table.group :table="$columns" key="only" title="Открыты только Вам" :count="$exclusive->count()"><x-slot:before><span class="card-tone card-tone--only"></span></x-slot:before></x-table.group>
                        @foreach ($exclusive as $offer)
                            <x-offer.site-table-row :offer="$offer" :context="$context" :table="$columns" only/>
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
                                <x-offer.card :offer="$offer" :context="$context" only cover/>
                            @endforeach
                        </x-ui.shelf>
                    </div>
                    @endif
                @endif
                @if ($offers->isEmpty())
                    {{-- Всё ушло в ленты сверху («Срочно», «Открыты только Вам») — «пусто» не пишем. --}}
                    @if ($urgent->isNotEmpty() || $exclusive->isNotEmpty())
                    @else
                        @include('site.catalog-empty')
                    @endif
                    @include('site.catalog-estimated')
                @else
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog" :view="$view" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="false" :table="$columns">
                        <x-slot:head><x-table.head :table="$columns" :sort="$sorting"/></x-slot:head>
                        @php $group = null; @endphp
                        @foreach ($offers as $offer)
                            @if ($groups && $group !== ($label = \App\Offers\CloseGroups::label($offer, $gallery)))
                                @php $group = $label; @endphp
                                <x-table.group :table="$columns" :key="'c'.md5($label)" :title="$label" :count="$groupSizes[$label] ?? null"/>
                            @endif
                            <x-offer.site-table-row :offer="$offer" :context="$context" :table="$columns"/>
                        @endforeach
                    </x-ui.table>
                    @elseif ($shelves)
                    {{-- По сроку плитками — пачка на ленту вбок (11.10.2026, владелец и начальник: «горизонтальный скролл в разы
                         удобнее»), весь список одной страницей. Выбор и живые правки — на обёртке, плитки ищутся вглубь. --}}
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="false" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        @foreach ($offers->getCollection()->groupBy(fn ($o) => \App\Offers\CloseGroups::label($o, $gallery)) as $label => $pack)
                            <x-ui.shelf :title="$label" :count="$groupSizes[$label] ?? $pack->count()">
                                @foreach ($pack as $offer)
                                    <x-offer.card :offer="$offer" :context="$context" cover/>
                                @endforeach
                            </x-ui.shelf>
                        @endforeach
                    </div>
                    @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="{{ ! $groups && $sort === '-published' ? 'true' : 'false' }}" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
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
