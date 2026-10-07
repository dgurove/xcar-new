<?php

namespace App\Http\Admin;

use App\Offers\Actions\CreateOffer;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Support\Detail;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use Illuminate\Http\Request;

/** Галерея — предложения «скоро в продаже»: без цены, копят интерес. Новое заводится черновиком, в галерею — с карточки. */
class GalleryController
{
    public const SORTS = ['fresh' => ['Дата публикации', 'desc'], 'interest' => ['Интерес', 'desc'], 'number' => ['Номер', 'desc']];

    public function index(Request $request, OfferController $offers)
    {
        $detail = Detail::of($request, fn (string $key) => $offers->detailOf($request, OfferNumber::find($key), gallery: true));
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-gallery', ...OfferController::facets());
        ListPrefs::sync($request, 'crm-gallery', keep: $facets->keys());
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        $q = Offer::query()->where('state', OfferState::Gallery)->with(['brand', 'model', 'settlement', 'parkVehicle:id,offer_id,category,accepted_at,created_at', 'deal', 'garageCar'])->withCount(['activeBids', 'interests']);
        if ($term = trim((string) $request->query('q'))) {
            $q->searchCrm($term);
        }
        $facets->apply($q);
        $dir = $sort->dir();
        match ($sort->key) {
            'interest' => $q->orderBy('interests_count', $dir)->orderByDesc('published_at'),
            'number' => $q->orderBy('number', $dir),
            default => $q->orderByRaw("published_at {$dir} nulls last"),
        };
        $q->orderByDesc('offers.id');

        $offers = ListView::paginate($request, $q);
        // Кадры нужны плиткам и строкам, в таблице их нет.
        if (! ListView::isTable(ListView::pick($request, $offers->total()))) {
            $offers->loadMissing('media');
        }

        return view('admin.gallery.index', [
            'offers' => $offers,
            'sort' => $sort,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user());

        return redirect("/offers/{$offer->number}");
    }
}
