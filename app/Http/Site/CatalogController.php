<?php

namespace App\Http\Site;

use App\Cars\Brand;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Support\ListContext;
use App\Support\ListView;
use Illuminate\Http\Request;

class CatalogController
{
    /** Главная: первый экран с числом предложений, ниже — каталог. С фильтрами в адресе — просто каталог. */
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
        $user = $request->user();
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => $v !== null && $v !== '');
        $view = ListView::fromRequest($request);
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices);
        $states = $gallery ? [OfferState::Gallery] : [OfferState::Open, OfferState::Closed];

        $counts = [
            'offers' => Offer::whereIn('state', [OfferState::Open, OfferState::Closed])->count(),
            'gallery' => Offer::where('state', OfferState::Gallery)->count(),
            'purchases' => $user?->role->canSeePurchases() ? Purchase::whereIn('state', [PurchaseState::Open, PurchaseState::Closed])->count() : 0,
        ];

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
            'hero' => ! $gallery && $filters === [] && $view === null && ! $request->has('page'),
        ]);
    }
}
