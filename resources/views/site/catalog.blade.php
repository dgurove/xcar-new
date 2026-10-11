{{-- Предложения и галерея: название раздела со счётчиком, тулбар, карточки. Первый экран с числом — на лендинге `/`. --}}
@php
    $user = auth()->user();
    $trail = [['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения']];
    $selecting = $user?->isManager() && !$gallery;
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
            <x-ui.toolbar class="md:mt-5" :sort="$sorting" :pills="$views" :dots="$gallery ? [] : ['fresh' => 'fresh', 'ending' => 'hot']" :pill="$filters['view'] ?? ''" :counts="$counts" :name="$gallery ? 'gallery' : 'catalog'" :facets="$facets" :search="filled($filters['q'] ?? null) ? 'Марка, модель, номер, VIN' : null" search-target="#catalog-list">
                <x-slot:extra>
                    @if ($selecting && $offers->isNotEmpty() && ! \App\Support\ListView::isTable($view))<button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="selection#toggle" data-selection-target="toggle" aria-pressed="false"><x-ui.icon name="check-circle" class="size-4"/><span class="hidden sm:inline">Выбрать</span></button>@endif
                    <x-ui.view-switch :current="$view"/>
                </x-slot:extra>
            </x-ui.toolbar>

            <div class="mt-6" id="catalog-list">
                {{-- Подписка на бот предложений — строкой в начале списка (владелец 03.10.2026), только в каталоге. --}}
                @unless ($gallery)<x-offers-bot.card/>@endunless
                {{-- Оценка менеджером (11.10.2026): что оценить — первой липкой пачкой плиток тем же видом, что список, с полосой
                     «ваш ход»; что уже оценено и ждёт решения XCar — строками под ней, без полосы: делать там нечего. --}}
                @php
                    $toEstimate = $estimates->reject(fn ($o) => $o->openEstimates->first()?->isGiven());
                    $estimated = $estimates->filter(fn ($o) => $o->openEstimates->first()?->isGiven());
                @endphp
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
                    <div class="{{ \App\Support\ListView::containerClass($view) }} mb-6" id="catalog-estimates">
                        <h2 class="cards-group">Нужно оценить <span class="nums">{{ $toEstimate->count() }}</span></h2>
                        @foreach ($toEstimate as $offer)
                            <x-offer.card :offer="$offer" :estimate="$offer->openEstimates->first()"/>
                        @endforeach
                    </div>
                    @endif
                @endif
                @if ($estimated->isNotEmpty())
                    <section class="mb-6" id="catalog-estimated">
                        <h2 class="list-head !px-0">Ждут решения <span class="nums">{{ $estimated->count() }}</span></h2>
                        {{-- Строки — сеткой: на ПК одна строка во всю ширину пустовала. --}}
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($estimated as $offer)
                                <a href="/offers/{{ $offer->number }}" class="row">
                                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate">{{ $offer->titleWithYear() }}</span>
                                        @php $e = $offer->openEstimates->first(); @endphp
                                        <span class="row-sub nums">{{ match (true) { $e->agreed() => 'вы согласны с ценой '.\App\Support\Money::rub($e->amount), $e->isCorrection() => 'ваша цена '.\App\Support\Money::rub($e->amount), default => 'ваша оценка '.\App\Support\Money::rub($e->amount) } }}</span>
                                    </span>
                                    <x-ui.chevron/>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if ($offers->isEmpty())
                    @if ($buyer && ! $narrowed)
                        {{-- Покупателю пока ничего не открыли: одна фраза и контакт менеджера строкой. --}}
                        <x-ui.empty id="catalog-empty" class="pb-0">{{ $manager ? $manager->shortName().' пока ничего вам не открыл' : 'Вам пока ничего не открыли' }}</x-ui.empty>
                        @if ($manager)<div class="list mx-auto mt-4 max-w-sm"><x-ui.person-row :user="$manager" :size="44" class="row"/></div>@endif
                    @else
                    {{-- «Сбросить» снимает чипы пустыми значениями, иначе ListPrefs вернёт запомненный город; пилюля и поиск — тоже.
                         Без предзагрузки: курсор над ссылкой стирал бы память фильтра. --}}
                    <x-ui.empty :href="$facets->resetUrl(['view' => null, 'q' => null])" :link="$narrowed ? 'Сбросить фильтры' : null" id="catalog-empty" data-turbo-prefetch="false">
                        @if ($narrowed) По этим условиям ничего нет @elseif ($gallery) Пока пусто @else Предложений пока нет @endif
                    </x-ui.empty>
                    @endif
                @else
                    @if (\App\Support\ListView::isTable($view))
                    <x-ui.table id="catalog" :view="$view" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="false">
                        <x-slot:head><x-offer.site-table-head :sort="$sorting" :deadline="$deadline"/></x-slot:head>
                        @php $group = null; @endphp
                        @foreach ($offers as $offer)
                            @if ($groups && $group !== ($label = \App\Offers\CloseGroups::label($offer)))@php $group = $label; @endphp<tr class="table-group"><th colspan="99" scope="colgroup">{{ $label }} <span class="nums">{{ $groupSizes[$label] ?? '' }}</span></th></tr>@endif
                            <x-offer.site-table-row :offer="$offer" :context="$context" :deadline="$deadline"/>
                        @endforeach
                    </x-ui.table>
                    @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" data-prepend="{{ in_array($sort, ['-published', '-opened'], true) ? 'true' : 'false' }}" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        {{-- По сроку — подписью над каждой пачкой слота («До 17:00 сегодня»), владелец 10.10.2026. --}}
                        @php $group = null; @endphp
                        @foreach ($offers as $offer)
                            @if ($groups && $group !== ($label = \App\Offers\CloseGroups::label($offer)))@php $group = $label; @endphp<h2 class="cards-group">{{ $label }} <span class="nums">{{ $groupSizes[$label] ?? '' }}</span></h2>@endif
                            <x-offer.card :offer="$offer" :context="$context"/>
                        @endforeach
                    </div>
                    @endif
                    <div class="mt-10"><x-ui.pager :of="$offers" :sizes="\App\Support\ListView::perSizes($view)"/></div>
                @endif
            </div>
        </div>
        @if ($selecting && $offers->isNotEmpty())
            {{-- Полоса режима выбора и шторка «Показать…» с фреймом под отмеченные. --}}
            <div data-controller="sheet" data-action="selection:open@window->sheet#open" class="contents">
                <x-ui.action-bar data-selection-target="bar" hidden>
                    <button type="button" class="btn btn-accent min-w-0 flex-1" data-action="selection#show" data-selection-submit disabled>Показать… <span class="nums" data-selection-target="count"></span></button>
                    <button type="button" class="btn btn-quiet btn-round btn-lg" data-action="selection#cancel" aria-label="Отмена"><x-ui.icon name="x" class="size-6"/></button>
                </x-ui.action-bar>
                <x-ui.sheet id="show-many" title="Показать покупателям" wide>
                    <turbo-frame id="show-frame" data-selection-target="frame" class="block min-h-40" target="_top">
                        <x-ui.skeleton :rows="3"/>
                    </turbo-frame>
                </x-ui.sheet>
            </div>
        @endif
    </div>
</x-ui.shell>
