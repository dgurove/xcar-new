{{-- Главная и галерея: первый экран (без фильтров), заголовки-переключатели разделов, тулбар, карточки. --}}
@php
    $user = auth()->user();
    $trail = $hero ? [] : [['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения']];
    $sections = array_filter([
        ['Предложения', $counts['offers'], '/', !$gallery],
        $counts['gallery'] || $gallery ? ['Галерея', $counts['gallery'], '/gallery', $gallery] : null,
        $counts['purchases'] ? ['Закупки', $counts['purchases'], '/purchases', false] : null,
    ]);
@endphp
@php
    $selecting = $user?->isManager() && !$gallery;
    // Покупателю — лента без каталожной обвязки: один заголовок, тулбар (поиск и сортировка)
    // только когда предложений больше дюжины, ничего лишнего.
    $buyer = $user?->isBuyer() ?? false;
    // Пилюля «Рекомендуем» короткую ленту не усложняет: сортировка и поиск от неё не появляются.
    $simple = $buyer && $counts['offers'] <= 12 && !array_diff_key($filters, ['view' => 1]);
    // Покупателю из пилюль только «Рекомендуем»: остальная каталожная обвязка ему ни к чему.
    $pills = ['' => 'Все', 'recommended' => 'Рекомендуем'];
@endphp
<x-ui.shell :title="$gallery ? 'Скоро в продаже' : ($hero ? null : 'Предложения')" :heading="false" :over-hero="$hero" :trail="$trail">
    @if ($hero)
        <x-ui.hero :count="$counts['offers']" :label="\App\Support\Plural::of($counts['offers'], ['предложение доступно', 'предложения доступно', 'предложений доступно'])" href="#catalog-section"/>
    @endif

    <div id="catalog-section" @class(['over-hero' => $hero]) @if ($selecting) data-controller="selection" data-selection-url-value="/account/showings/new" @endif>
        {{-- Без первого экрана контейнер уже даёт шелл — второй удваивал поля. --}}
        <div @class(['container-site pt-10 pb-10 sm:pt-14 sm:pb-14' => $hero])>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                @foreach ($sections as [$label, $count, $href, $current])
                    <x-ui.section-title :count="$count" :href="$current ? null : $href" :current="$current" :level="$current ? 'h1' : 'h2'">{{ $label }}</x-ui.section-title>
                @endforeach
            </div>

            @if ($simple)
                {{-- В короткой ленте тулбара нет, но если менеджер что-то рекомендовал — одни пилюли. --}}
                @if ($counts['recommended'])<x-ui.toolbar class="mt-5" :pills="$pills" :pill="$filters['view'] ?? ''" :counts="$counts" name="catalog"/>@endif
            @elseif ($buyer)
            <x-ui.toolbar class="mt-5" :sorts="$sorts" :sort="$sort" sort-side="right" :pills="$counts['recommended'] ? $pills : []" :pill="$filters['view'] ?? ''" :counts="$counts" :hidden="[\App\Support\ListView::PARAM => $view]" name="catalog">
                <x-slot:filters>
                    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Марка, модель" class="field-input field-s">
                </x-slot:filters>
            </x-ui.toolbar>
            @else
            <x-ui.toolbar class="mt-5" :sorts="$sorts" :sort="$sort" :pills="$views" :pill="$filters['view'] ?? ''" :counts="$counts" :hidden="[\App\Support\ListView::PARAM => $view]" :name="$gallery ? 'gallery' : 'catalog'">
                <x-slot:extra>
                    @if ($selecting && $offers->isNotEmpty() && $view !== \App\Support\ListView::TABLE)<button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="selection#toggle" data-selection-target="toggle" aria-pressed="false"><x-ui.icon name="check-circle" class="size-4"/><span class="hidden sm:inline">Выбрать</span></button>@endif
                    <x-ui.view-switch :current="$view"/>
                </x-slot:extra>
                <x-slot:filters>
                    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Марка, модель, VIN" class="field-input field-s">
                    <select name="brand" class="field-input field-s" aria-label="Марка">
                        <option value="">Любая марка</option>
                        @foreach ($brands as $brand)<option value="{{ $brand->slug }}" @selected(($filters['brand'] ?? '') === $brand->slug)>{{ $brand->name }}</option>@endforeach
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <input name="year_from" value="{{ $filters['year_from'] ?? '' }}" placeholder="Год от" inputmode="numeric" class="field-input field-s nums">
                        <input name="year_to" value="{{ $filters['year_to'] ?? '' }}" placeholder="до" inputmode="numeric" class="field-input field-s nums">
                    </div>
                    @if ($prices)
                        <div class="grid grid-cols-2 gap-2">
                            <input name="price_from" value="{{ $filters['price_from'] ?? '' }}" placeholder="Цена от" inputmode="numeric" class="field-input field-s nums">
                            <input name="price_to" value="{{ $filters['price_to'] ?? '' }}" placeholder="до" inputmode="numeric" class="field-input field-s nums">
                        </div>
                    @endif
                </x-slot:filters>
            </x-ui.toolbar>
            @endif

            <div class="mt-6">
                @if ($offers->isEmpty())
                    @if ($buyer && !$filters)
                        {{-- Покупателю пока ничего не открыли: одна фраза и контакт менеджера строкой. --}}
                        <x-ui.empty id="catalog-empty" class="!py-16">{{ $manager ? $manager->shortName().' пока ничего вам не открыл.' : 'Вам пока ничего не открыли.' }}</x-ui.empty>
                        @if ($manager)
                            @php $tag = $manager->phone ? 'a' : 'div'; @endphp
                            <{{ $tag }} @if ($manager->phone) href="tel:+{{ $manager->phone }}" @endif class="row mx-auto mt-4 max-w-sm">
                                <x-ui.avatar :user="$manager" :size="44"/>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $manager->name }}</span>
                                    @if ($manager->phone)<span class="row-sub"><span class="nums">{{ $manager->phoneFormatted() }}</span></span>@endif
                                </span>
                                @if ($manager->phone)<span class="btn btn-s btn-quiet btn-round"><x-ui.icon name="phone" class="size-5"/></span>@endif
                            </{{ $tag }}>
                        @endif
                    @else
                    <x-ui.empty :href="$gallery ? '/gallery' : '/'" :link="$filters ? 'Сбросить фильтры' : null" id="catalog-empty">
                        @if ($filters) По этим условиям ничего нет. @elseif ($gallery) Пока пусто. @else Предложений пока нет. @endif
                    </x-ui.empty>
                    @endif
                @else
                    @if ($view === \App\Support\ListView::TABLE)
                    <x-ui.table id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}">
                        <x-slot:head><x-offer.site-table-head/></x-slot:head>
                        @foreach ($offers as $offer)<x-offer.site-table-row :offer="$offer" :context="$context"/>@endforeach
                    </x-ui.table>
                    @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        @foreach ($offers as $offer)<x-offer.card :offer="$offer" :context="$context"/>@endforeach
                    </div>
                    @endif
                    <div class="mt-10">{{ $offers->links() }}</div>
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
