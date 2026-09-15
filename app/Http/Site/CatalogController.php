<?php

namespace App\Http\Site;

use App\Cars\Brand;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Purchases\Purchase;
use App\Support\ListContext;
use App\Http\Middleware\MarkInstalled;
use App\Support\ListPrefs;
use App\Support\ListView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CatalogController
{
    /** Главная: первый экран с числом предложений, ниже — каталог. С фильтрами в адресе — просто каталог. */
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
        return $this->list($request, gallery: true);
    }

    private function list(Request $request, bool $gallery)
    {
        $user = $request->user();
        ListPrefs::sync($request, $gallery ? 'gallery' : 'catalog');
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== '');
        $view = ListView::fromRequest($request);
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices);
        $states = $gallery ? [OfferState::Gallery] : [OfferState::Open];

        // Три счётчика на каждый запрос списка — полминуты в кэше, слабому серверу легче.
        $counts = Cache::remember('catalog.counts', 30, fn () => [
            'offers' => Offer::where('state', OfferState::Open)->count(),
            'gallery' => Offer::where('state', OfferState::Gallery)->count(),
            // Закупок на витрине столько, сколько карточек: одна присланная — две (легковые и грузовые).
            'purchases' => count(Purchase::showcase(null)),
        ]);
        $counts['purchases'] = $user?->role->canSeePurchases() ? $counts['purchases'] : 0;

        return view('site.catalog', [
            'offers' => CatalogQuery::for($user, $filters + ['sort' => $sort], $gallery)->paginate(CatalogQuery::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'sort' => $sort,
            'sorts' => CatalogQuery::allowedSorts($gallery, $prices),
            'views' => CatalogQuery::allowedViews($user, $gallery),
            'view' => $view,
            'context' => ListContext::forList($gallery, $filters + ['sort' => $sort], $view),
            'brands' => Brand::whereHas('offers', fn ($o) => $o->whereIn('state', $states))->orderBy('name')->get(),
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => $counts,
            // Установленное приложение открывается сразу в список, первый экран — гостю в браузере.
            'hero' => ! $gallery && $filters === [] && $view === null && ! $request->has('page') && ! MarkInstalled::installed($request),
        ]);
    }
}
