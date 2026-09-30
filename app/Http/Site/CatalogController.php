<?php

namespace App\Http\Site;

use App\Cars\Brand;
use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
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
        $user = $request->user();
        ListPrefs::sync($request, $gallery ? 'gallery' : 'catalog');
        $filters = array_filter($request->only(CatalogQuery::FILTERS), fn ($v) => is_scalar($v) && $v !== '');
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);
        $sort = CatalogQuery::sort($filters, $gallery, $prices, $user);
        $states = $gallery ? [OfferState::Gallery] : [OfferState::Open];

        $query = CatalogQuery::for($user, $filters + ['sort' => $sort], $gallery);
        $count = $query->count();
        if (ListView::isTable(ListView::pick($request, $count))) {
            // Строке таблицы кадры не нужны.
            $query->without('media');
        }

        // Счётчики на каждый запрос списка — полминуты в кэше, слабому серверу легче.
        // Сотруднику — общие; менеджеру и покупателю выдача своя, считаем по ней и без кэша: после «Показать…» число должно сойтись сразу.
        $counts = $user?->isStaff()
            ? Cache::remember('catalog.counts', 30, fn () => [
                'offers' => Offer::where('state', OfferState::Open)->count(),
                'gallery' => Offer::where('state', OfferState::Gallery)->count(),
                'recommended' => Offer::where('state', OfferState::Open)->where('recommended', true)->count(),
                'recommended_gallery' => Offer::where('state', OfferState::Gallery)->where('recommended', true)->count(),
            ])
            : [
                // Без фильтров «Все» — это и есть длина списка: второй раз не считаем.
                'offers' => ! $gallery && ! array_diff_key($filters, ['sort' => 1]) ? $count : Offer::visibleTo($user)->where('state', OfferState::Open)->count(),
                'gallery' => $user?->role->canSeeGallery() ? Offer::visibleTo($user)->where('state', OfferState::Gallery)->count() : 0,
                'recommended' => Offer::visibleTo($user)->whereIn('state', $states)->where('recommended', true)->count(),
            ];
        // «Рекомендуем» — сколько отмеченных в этом разделе: пилюля с числом, без отмеченных пилюли нет.
        $recommended = $counts[$gallery && $user?->isStaff() ? 'recommended_gallery' : 'recommended'] ?? 0;

        $offers = ListView::paginate($request, $query, $count);
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
            // Марки для фильтра меняются редко — полминуты в кэше (свои у каждого, кому выдача своя). В кэше —
            // массивы, не модели: кэш объектов не восстанавливает (`serializable_classes` выключен).
            'brands' => collect(Cache::remember('catalog.brands:'.($gallery ? 'g' : 'o').':'.($user?->isStaff() ? 'staff' : ($user?->id ?? 0)), 30,
                fn () => Brand::whereHas('offers', fn ($o) => $o->visibleTo($user)->whereIn('state', $states))->orderBy('name')->get(['slug', 'name'])
                    ->map(fn (Brand $b) => ['slug' => $b->slug, 'name' => $b->name])->all()))->map(fn (array $b) => (object) $b),
            'gallery' => $gallery,
            'prices' => $prices,
            'counts' => ['recommended' => $recommended] + $counts,
            'manager' => $user?->isBuyer() ? $user->manager : null,
        ]);
    }
}
