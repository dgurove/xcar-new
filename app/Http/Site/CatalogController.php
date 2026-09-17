<?php

namespace App\Http\Site;

use App\Cars\Brand;
use App\Http\Middleware\MarkInstalled;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Purchases\Purchase;
use App\Support\ListContext;
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
        abort_unless($request->user()?->role->canSeeGallery() ?? true, 404);

        return $this->list($request, gallery: true);
    }

    private function list(Request $request, bool $gallery)
    {
        $user = $request->user();
        ListPrefs::sync($request, $gallery ? 'gallery' : 'catalog');
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== '');
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices, $user);
        $states = $gallery ? [OfferState::Gallery] : [OfferState::Open];

        // Три счётчика на каждый запрос списка — полминуты в кэше, слабому серверу легче.
        // Сотруднику — общие; менеджеру и покупателю выдача своя, считаем по ней и без кэша: после «Показать…» число должно сойтись сразу.
        $counts = $user?->isStaff()
            ? Cache::remember('catalog.counts', 30, fn () => [
                'offers' => Offer::where('state', OfferState::Open)->count(),
                'gallery' => Offer::where('state', OfferState::Gallery)->count(),
                // Закупок на витрине столько, сколько карточек: одна присланная — две (легковые и грузовые).
                'purchases' => count(Purchase::showcase(null)),
                'recommended' => Offer::where('state', OfferState::Open)->where('recommended', true)->count(),
                'recommended_gallery' => Offer::where('state', OfferState::Gallery)->where('recommended', true)->count(),
            ])
            : [
                'offers' => Offer::visibleTo($user)->where('state', OfferState::Open)->count(),
                'gallery' => $user?->role->canSeeGallery() ? Offer::visibleTo($user)->where('state', OfferState::Gallery)->count() : 0,
                'purchases' => $user?->role->canSeePurchases() ? count(Purchase::showcase($user)) : 0,
                'recommended' => Offer::visibleTo($user)->whereIn('state', $states)->where('recommended', true)->count(),
            ];
        $counts['purchases'] = $user?->role->canSeePurchases() ? $counts['purchases'] : 0;
        // «Рекомендуем» — сколько отмеченных в этом разделе: пилюля с числом, без отмеченных пилюли нет.
        $recommended = $counts[$gallery && $user?->isStaff() ? 'recommended_gallery' : 'recommended'] ?? 0;

        $offers = CatalogQuery::for($user, $filters + ['sort' => $sort], $gallery)->paginate(CatalogQuery::PER_PAGE)->withQueryString();
        Showing::remember($user, $offers->pluck('id')->all());
        $view = ListView::pick($request, $offers->total());

        return view('site.catalog', [
            'offers' => $offers,
            'filters' => $filters,
            'sort' => $sort,
            'sorts' => CatalogQuery::allowedSorts($gallery, $prices, $user),
            'views' => CatalogQuery::allowedViews($user, $gallery, $recommended),
            'view' => $view,
            'context' => ListContext::forList($gallery, $filters + ['sort' => $sort], $view),
            'brands' => Brand::whereHas('offers', fn ($o) => $o->visibleTo($user)->whereIn('state', $states))->orderBy('name')->get(),
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => ['recommended' => $recommended] + $counts,
            // Установленное приложение открывается сразу в список, первый экран — гостю в браузере.
            // Покупателю первый экран ни к чему: у него не витрина, а то, что открыл менеджер.
            'hero' => ! $gallery && $filters === [] && $view === null && ! $request->has('page') && ! MarkInstalled::installed($request) && ! $user?->isBuyer(),
            'manager' => $user?->isBuyer() ? $user->manager : null,
        ]);
    }
}
