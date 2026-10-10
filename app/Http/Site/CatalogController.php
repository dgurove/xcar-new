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
        $facets = Facets::for($request, $gallery ? 'gallery' : 'catalog', ...CatalogQuery::facets());
        ListPrefs::sync($request, $gallery ? 'gallery' : 'catalog', keep: $facets->keys());
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== '');
        $prices = ! $gallery && ($user?->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices, $user);
        // Сужено — чем угодно, кроме сортировки: запомненная сортировка (ListPrefs) список не сужает.
        $narrowed = (bool) array_diff_key($filters, ['sort' => 1]);

        // Чипы кладёт Facets (ему нужна база без них — для вариантов и чисел), остальное — CatalogQuery.
        $query = $facets->apply(CatalogQuery::for($user, array_diff_key($filters, array_flip($facets->keys())) + ['sort' => $sort], $gallery));
        $count = $query->count();
        if (ListView::isTable(ListView::pick($request, $count))) {
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
                'offers' => ! $gallery && ! $narrowed ? $count : Offer::siteList($user)->count(),
                'gallery' => Offer::visibleTo($user)->inGallery()->count(),
                'recommended' => ($gallery ? Offer::visibleTo($user)->inGallery() : Offer::siteList($user))->where('recommended', true)->count(),
            ];
        // «Рекомендуем» — пилюля есть, пока в разделе есть отмеченные.
        $recommended = $counts[$gallery && $user?->isAdmin() ? 'recommended_gallery' : 'recommended'] ?? 0;
        $views = CatalogQuery::allowedViews($user, $gallery, $recommended);
        // Числа на пилюлях — с выбранными чипами (город, тип ТС): сколько покажет нажатие. Считаем только пилюли, которые
        // человеку видны; «новое» у каждого своё (волна, показ) — не из общего кэша.
        $section = fn () => $facets->applyTo($gallery ? Offer::visibleTo($user)->inGallery() : Offer::siteList($user));
        $pills = [];
        foreach (array_intersect(['recommended', 'fresh', 'ending'], array_keys($views)) as $key) {
            $pills[$key] = match ($key) {
                'recommended' => $section()->where('recommended', true)->count(),
                'fresh' => CatalogQuery::fresh($section(), $user, $gallery)->count(),
                'ending' => CatalogQuery::ending($section())->count(),
            };
        }

        $offers = ListView::paginate($request, $query, $count);
        Showing::remember($user, $offers->pluck('id')->all());
        $view = ListView::pick($request, $offers->total());

        return view('site.catalog', [
            'detail' => $detail,
            'offers' => $offers,
            'filters' => $filters,
            'narrowed' => $narrowed,
            'sort' => $sort,
            // Сортировка объектом — тулбару и заголовкам таблицы; строкой `sort` — стрелкам страницы предложения.
            'sorting' => Sort::from($sort, CatalogQuery::allowedSorts($gallery, $prices, $user), CatalogQuery::defaultSort($user, $gallery)),
            // Подписи «До 17:00 сегодня» между пачками — только по сроку и тому, кто срок видит.
            'groups' => ! $gallery && $sort === 'closing' && ($user?->bidsOnSite() ?? false),
            'views' => $views,
            'view' => $view,
            'context' => ListContext::forList($gallery, $filters + ['sort' => $sort], $view),
            'facets' => $facets,
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => $pills + $counts,
            'manager' => $user?->isBuyer() ? $user->manager : null,
        ]);
    }
}
