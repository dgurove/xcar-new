<?php

namespace App\Http\Site;

use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Support\Detail;
use App\Support\Facets\Facets;
use App\Support\ListContext;
use App\Support\ListPrefs;
use App\Support\ListView;
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
    /** Живой поиск в шторке: до восьми строк по мере ввода, фреймом. */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $offers = mb_strlen($q) >= 2
            ? CatalogQuery::for($request->user(), ['q' => $q, 'sort' => CatalogQuery::DEFAULT_SORT])->limit(8)->get()
            : collect();

        return view('site.search', ['offers' => $offers, 'q' => $q]);
    }

    public function gallery(Request $request)
    {
        abort_unless($request->user()?->role->canSeeGallery() ?? true, 404);

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
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices, $user);

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
        $counts = $user?->isStaff()
            ? Cache::remember('catalog.counts', 30, fn () => [
                'offers' => Offer::onSale()->count(),
                'gallery' => Offer::where('state', OfferState::Gallery)->count(),
                'recommended' => Offer::onSale()->where('recommended', true)->count(),
                'recommended_gallery' => Offer::where('state', OfferState::Gallery)->where('recommended', true)->count(),
            ])
            : [
                // Без фильтров «Все» — это и есть длина списка: второй раз не считаем.
                'offers' => ! $gallery && ! array_diff_key($filters, ['sort' => 1]) ? $count : Offer::visibleTo($user)->onSale()->count(),
                'gallery' => $user?->role->canSeeGallery() ? Offer::visibleTo($user)->where('state', OfferState::Gallery)->count() : 0,
                'recommended' => Offer::visibleTo($user)->when($gallery, fn ($q) => $q->where('state', OfferState::Gallery), fn ($q) => $q->onSale())->where('recommended', true)->count(),
            ];
        // «Рекомендуем» — сколько отмеченных в этом разделе: пилюля с числом, без отмеченных пилюли нет.
        $recommended = $counts[$gallery && $user?->isStaff() ? 'recommended_gallery' : 'recommended'] ?? 0;

        $offers = ListView::paginate($request, $query, $count);
        Showing::remember($user, $offers->pluck('id')->all());
        $view = ListView::pick($request, $offers->total());

        return view('site.catalog', [
            'detail' => $detail,
            'offers' => $offers,
            'filters' => $filters,
            'sort' => $sort,
            'sorts' => CatalogQuery::allowedSorts($gallery, $prices, $user),
            'views' => CatalogQuery::allowedViews($user, $gallery, $recommended),
            'view' => $view,
            'context' => ListContext::forList($gallery, $filters + ['sort' => $sort], $view),
            'facets' => $facets,
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => ['recommended' => $recommended] + $counts,
            'manager' => $user?->isBuyer() ? $user->manager : null,
        ]);
    }
}
