<?php

namespace App\Http\Site;

use App\Offers\CatalogQuery;
use App\Offers\Offer;
use App\Support\Ago;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\PagePart;
use App\Support\Portion;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Архив предложений менеджера (23.11.2026, начальник: «все предложения, что ушли за последние 30 дней — зайти и написать админам
 * по ней»): `/offers/archive`, пилюлей «Архив» в ряду «Предложений». Что сюда попадает — одна дверь `Offer::scopeArchivedFor`;
 * почему ушло, здесь не видно нигде: плитка `x-offer.archive-card`, страница `site/offers/archived`. Новые сверху, подписями по
 * дню ухода; лента без страниц, как каталог (`PagePart`, хвост `site/archive-part`). Вид — только плитки или строки.
 */
class ArchiveController
{
    public const VIEWS = [ListView::GRID, ListView::LIST];

    public function index(Request $request)
    {
        $user = $request->user();
        $facets = Facets::for($request, 'archive', ...CatalogQuery::facets($user));
        $request->query->remove('page');
        $request->query->remove(ListView::PER);
        ListPrefs::sync($request, 'archive', keep: array_values(array_diff($facets->keys(), ['regions'])), sort: false);
        $q = trim((string) $request->query('q', ''));
        $base = Offer::archivedFor($user)->search($q);
        $query = $facets->apply((clone $base)->with(['settlement', 'saleSettlement', 'media' => fn ($m) => $m->where('collection_name', 'photos')])->latestLeft());
        $count = $query->count();
        $view = in_array($picked = ListView::pick($request, $count), self::VIEWS, true) ? $picked : ListView::GRID;
        // Хвост ленты (`endless_controller`, `X-List` с порцией) — только плитки; `PagePart` номер порции из запроса снимает.
        $tail = $request->headers->has('X-List') && (int) $request->query(Portion::PART) > 1;
        $offers = PagePart::of($request, $query, $count, max($count, 1));

        if ($tail) {
            return view('site.archive-part', ['offers' => $offers, 'before' => $offers->before, 'view' => $view]);
        }

        // Ряд пилюль — тот же, что у «Предложений» (пилюли ведут туда), «Архив» последней и выбран.
        $views = CatalogQuery::allowedViews($user, false, Offer::siteList($user)->where('recommended', true)->count(), Offer::siteList($user)->urgent()->count());

        return view('site.archive', [
            'offers' => $offers,
            'view' => $view,
            'views' => $views,
            'facets' => $facets,
            'q' => $q,
            'narrowed' => $q !== '' || $facets->active(),
            'total' => $q === '' && ! $facets->active() ? $count : Offer::archivedFor($user)->count(),
        ]);
    }

    /** Подпись дня ухода над плитками: «Сегодня», «Вчера», «20.11» (другой год — с годом). */
    public static function day(Offer $offer): string
    {
        return Str::ucfirst(Ago::at($offer->leftAt()));
    }
}
