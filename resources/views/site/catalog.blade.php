{{-- Главная и галерея: первый экран (без фильтров), заголовки-переключатели разделов, тулбар, карточки. --}}
@php
    $user = auth()->user();
    $trail = $hero ? [] : [['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения']];
    $sections = array_filter([
        ['Предложения', $counts['offers'], '/', !$gallery],
        $counts['gallery'] || $gallery ? ['Галерея', $counts['gallery'], '/galereya', $gallery] : null,
        $counts['purchases'] ? ['Закупки', $counts['purchases'], '/zakupki', false] : null,
    ]);
@endphp
@php $selecting = $user?->isManager() && !$gallery; @endphp
<x-ui.shell :title="$gallery ? 'Скоро в продаже' : ($hero ? null : 'Предложения')" :heading="false" :over-hero="$hero" :trail="$trail">
    @if ($hero)
        <x-ui.hero :count="$counts['offers']" :label="\App\Support\Plural::of($counts['offers'], ['предложение доступно', 'предложения доступно', 'предложений доступно'])" href="#catalog-section"/>
    @endif

    <div id="catalog-section" @class(['over-hero' => $hero]) @if ($selecting) data-controller="selection" data-selection-url-value="/lk/pokazy/novyy" @endif>
        <div @class(['container-site pb-10 sm:pb-14', 'pt-10 sm:pt-14' => $hero])>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                @foreach ($sections as [$label, $count, $href, $current])
                    <x-ui.section-title :count="$count" :href="$current ? null : $href" :current="$current" :level="$current ? 'h1' : 'h2'">{{ $label }}</x-ui.section-title>
                @endforeach
            </div>

            <x-ui.toolbar class="mt-5" :sorts="$sorts" :sort="$sort" :pills="$views" :pill="$filters['view'] ?? ''" :hidden="[\App\Support\ListView::PARAM => $view]" :name="$gallery ? 'gallery' : 'catalog'">
                <x-slot:extra>
                    @if ($selecting && $offers->isNotEmpty())<button type="button" class="btn btn-s btn-quiet shrink-0 rounded-full" data-action="selection#toggle" data-selection-target="toggle" aria-pressed="false"><x-ui.icon name="check-circle" class="size-4"/><span class="hidden sm:inline">Выбрать</span></button>@endif
                    <x-ui.view-switch/>
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

            <div class="mt-6">
                @if ($offers->isEmpty())
                    @if ($manager && !$filters)
                        {{-- Покупателю пока ничего не открыли: кто его менеджер и как с ним связаться. --}}
                        <div class="empty" id="catalog-empty">
                            <p>{{ $manager->shortName() }} пока ничего вам не открыл. Как только откроет — предложения появятся здесь, а вам придёт уведомление.</p>
                            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                                <x-ui.person :user="$manager" full/>
                                @if ($manager->phone)<a href="tel:+{{ $manager->phone }}" class="btn btn-s btn-quiet rounded-full">Позвонить</a>@endif
                            </div>
                        </div>
                    @else
                    <x-ui.empty :href="$gallery ? '/galereya' : '/'" :link="$filters ? 'Сбросить фильтры' : null" id="catalog-empty">
                        @if ($filters) По этим условиям ничего нет. @elseif ($gallery) Пока пусто. @else Предложений пока нет. @endif
                    </x-ui.empty>
                    @endif
                @else
                    <div id="catalog" data-list="{{ $gallery ? 'gallery' : 'catalog' }}" class="{{ \App\Support\ListView::containerClass($view) }}" data-controller="ticker" @if ($selecting) data-selection-target="list" data-action="click->selection#tap:capture change->selection#change" @endif>
                        @foreach ($offers as $offer)<x-offer.card :offer="$offer" :context="$context"/>@endforeach
                    </div>
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
