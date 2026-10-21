<?php

namespace App\Http\Site;

use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\Showing;
use App\Support\Detail;
use App\Support\Facets\Facets;
use App\Support\ListContext;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogController
{
    /** Предложения: каталог. Первый экран с числом — на лендинге `/` для гостя. */
    public function index(Request $request)
    {
        return $this->list($request, gallery: false);
    }

    /** Галерея «скоро в продаже»: без цены, принимаем интерес. */
    public function gallery(Request $request)
    {
        return $this->list($request, gallery: true);
    }

    private function list(Request $request, bool $gallery)
    {
        $detail = Detail::of($request, fn (string $key) => ($offer = OfferNumber::find($key)) && $offer->isVisibleTo($request->user()) ? app(OfferController::class)->detail($request, $offer) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $user = $request->user();
        // «Не просмотренные» (21.10.2026) — первым чипом у вошедшего в каталоге; не запоминается: каждый заход снова включён.
        $since = ! $gallery && $user ? CatalogQuery::since($request, enter: true) : null;
        $facets = Facets::for($request, $gallery ? 'gallery' : 'catalog', ...[...($since ? [CatalogQuery::unreadFacet($user, $since)] : []), ...CatalogQuery::facets()]);
        ListPrefs::sync($request, $gallery ? 'gallery' : 'catalog', keep: array_values(array_diff($facets->keys(), ['unread'])), sort: false);
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== '');
        $prices = ! $gallery && ($user?->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices, $user);
        // Сужено — чем угодно, кроме сортировки и «Не просмотренных»: запомненная сортировка (ListPrefs) список не сужает,
        // а непросмотренное — сам список захода.
        $narrowed = (bool) array_diff_key($filters, ['sort' => 1, 'unread' => 1]);
        $unread = $since && $facets->on('unread');

        // Чипы кладёт Facets (ему нужна база без них — для вариантов и чисел), остальное — CatalogQuery.
        $query = $facets->apply(CatalogQuery::for($user, array_diff_key($filters, array_flip($facets->keys())) + ['sort' => $sort], $gallery));
        // «Срочно» (12.10.2026, владелец): срочные с идущим приёмом — первой лентой, ближний срок выше; из основного списка
        // уходят, как «Открыты только Вам». С тем же фильтром; в поиске и на пилюле «Срочные» — нет.
        $urgent = ! $gallery && blank($filters['q'] ?? null) && blank($filters['view'] ?? null)
            ? (clone $query)->urgent()->reorder()->orderBy('offers.bids_close_at')->get() : collect();
        if ($urgent->isNotEmpty()) {
            $query->whereNotIn('offers.id', $urgent->pluck('id'));
        }
        // «Открыты только Вам» (11.10.2026, владелец): открытое одному этому менеджеру — своей пачкой выше остальных (после
        // «Нужно оценить»), из основного списка уходит, чтобы не стоять дважды. С тем же фильтром и сортировкой; в поиске — нет.
        $exclusive = ! $gallery && $user?->isManager() && blank($filters['q'] ?? null)
            ? (clone $query)->onlyFor($user)->get() : collect();
        if ($exclusive->isNotEmpty()) {
            $query->notOnlyFor($user);
        }
        $count = $query->count();
        if (ListView::isTable(ListView::pick($request, $count, shelves: true))) {
            // Строке таблицы кадры не нужны.
            $query->without('media');
        }

        // Счётчики на каждый запрос списка — полминуты в кэше, слабому серверу легче.
        // Сотруднику — общие; менеджеру и покупателю выдача своя, считаем по ней и без кэша: после «Показать…» число должно сойтись сразу.
        // Везде — только то, что в продаже (`Offer::scopeOnSale`): срок вышел или идёт сделка — с сайта ушло.
        $counts = $user?->isAdmin()
            ? Cache::remember('catalog.counts', 30, fn () => [
                'offers' => Offer::onSale()->count(),
                'gallery' => Offer::inGallery()->count(),
                'recommended' => Offer::onSale()->where('recommended', true)->count(),
                'recommended_gallery' => Offer::inGallery()->where('recommended', true)->count(),
            ])
            : [
                // Без фильтров «Все» — это и есть длина списка: второй раз не считаем. Та же дверь, что у списка и таба
                // (`Offer::siteList`: со сделками, открытыми менеджеру), — иначе с фильтром число расходилось с табом.
                'offers' => ! $gallery && ! $narrowed ? $count + $exclusive->count() + $urgent->count() : Offer::siteList($user)->count(),
                'gallery' => Offer::visibleTo($user)->inGallery()->count(),
                'recommended' => ($gallery ? Offer::visibleTo($user)->inGallery() : Offer::siteList($user))->where('recommended', true)->count(),
            ];
        // «Рекомендуем» — пилюля есть, пока в разделе есть отмеченные.
        $recommended = $counts[$gallery && $user?->isAdmin() ? 'recommended_gallery' : 'recommended'] ?? 0;
        $urgentCount = $gallery ? 0 : $facets->applyTo(Offer::siteList($user))->urgent()->count();
        $views = CatalogQuery::allowedViews($user, $gallery, $recommended, $urgentCount);
        // Числа на пилюлях — с выбранными чипами (город, тип ТС): сколько покажет нажатие. Считаем только пилюли, которые
        // человеку видны; «новое» у каждого своё (волна, показ) — не из общего кэша.
        $section = fn () => $facets->applyTo($gallery ? Offer::visibleTo($user)->inGallery() : Offer::siteList($user));
        $pills = [];
        foreach (array_intersect(['urgent', 'recommended', 'fresh', 'ending'], array_keys($views)) as $key) {
            $pills[$key] = match ($key) {
                'urgent' => $urgentCount,
                'recommended' => $section()->where('recommended', true)->count(),
                'fresh' => CatalogQuery::fresh($section(), $user, $gallery)->count(),
                'ending' => CatalogQuery::ending($section())->count(),
            };
        }

        // Пачки по сроку («До 17:00 сегодня») — только по сроку и тому, кто срок видит; лентами они — ленты вбок, весь
        // список одной страницей: пачку, порванную страницей, в ленте не долистать (11.10.2026). Вид по умолчанию — ленты
        // («Срочно», «Нужно оценить» и «Открыты только Вам» лентами и без пачек по сроку); «Плитками» — одна сетка со
        // страницами, без подписей пачек (15.10.2026, начальник).
        $groups = CatalogQuery::grouped($user, $gallery);
        $shelves = $groups && ListView::pick($request, $count, shelves: true) === ListView::SHELF;
        $offers = ListView::paginate($request, $query, $count, whole: $shelves);
        // «Нужно оценить» (11.10.2026): черновики, отданные этому менеджеру на оценку, — первой пачкой на первой странице
        // каталога, без пилюли и поиска. Сначала неоценённые, ближний срок выше; оценённые — ждут решения админа.
        $estimates = ! $gallery && $user?->isManager() && blank($filters['view'] ?? null) && blank($filters['q'] ?? null) && $offers->currentPage() === 1
            ? CatalogQuery::cards($user)->askedOf($user)->with(['openEstimates' => fn ($e) => $e->where('user_id', $user->id)])->get()
                ->sortBy(fn (Offer $o) => [$o->openEstimates->first()?->isGiven() ? 1 : 0, $o->openEstimates->first()?->due_at?->timestamp])->values()
            : collect();
        Showing::remember($user, $offers->pluck('id')->all());
        // Всё просмотрено, а в продаже есть — пустой экран говорит это и ведёт на весь список.
        $allSeen = $unread && blank($filters['q'] ?? null) && $offers->isEmpty() && $urgent->isEmpty() && $exclusive->isEmpty()
            && $facets->applyTo(Offer::siteList($user))->exists();
        $view = ListView::pick($request, $offers->total(), shelves: true);

        return view('site.catalog', [
            'detail' => $detail,
            'offers' => $offers,
            'filters' => $filters,
            'narrowed' => $narrowed,
            'sort' => $sort,
            // Сортировка объектом — тулбару и заголовкам таблицы; строкой `sort` — стрелкам страницы предложения.
            'sorting' => Sort::from($sort, CatalogQuery::allowedSorts($gallery, $prices, $user), CatalogQuery::defaultSort($user, $gallery)),
            'groups' => $groups,
            'exclusive' => $offers->currentPage() === 1 ? $exclusive : collect(),
            'urgent' => $offers->currentPage() === 1 ? $urgent : collect(),
            'shelves' => $shelves,
            'groupSizes' => $groups ? \App\Offers\CloseGroups::sizes($query, $gallery) : [],
            'views' => $views,
            'view' => $view,
            // Стрелкам страницы предложения — и «Не просмотренные» явно: без параметра там список целиком.
            'context' => ListContext::forList($gallery, array_merge($filters, ['sort' => $sort], $since ? ['unread' => $unread ? '1' : '0'] : [])),
            'allSeen' => $allSeen,
            'facets' => $facets,
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => $pills + $counts,
            'manager' => $user?->isBuyer() ? $user->manager : null,
            'estimates' => $estimates,
        ]);
    }
}
