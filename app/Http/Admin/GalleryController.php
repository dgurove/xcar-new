<?php

namespace App\Http\Admin;

use App\Offers\Actions\CreateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use Illuminate\Http\Request;

/** Галерея — предложения «скоро в продаже»: без цены, копят интерес. Новое заводится черновиком, в галерею — с карточки. */
class GalleryController
{
    public const SORTS = ['fresh' => 'Сначала новые', 'interest' => 'По интересу', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        $facets = Facets::for($request, 'crm-gallery', ...OfferController::facets());
        ListPrefs::sync($request, 'crm-gallery', keep: $facets->keys());
        $sort = $request->query('sort', 'fresh');
        $q = Offer::query()->where('state', OfferState::Gallery)->with(['brand', 'model', 'settlement', 'parkVehicle:id,offer_id,category,accepted_at,created_at'])->withCount(['activeBids', 'interests']);
        if ($term = trim((string) $request->query('q'))) {
            $q->searchCrm($term);
        }
        $facets->apply($q);
        match ($sort) {
            'interest' => $q->orderByDesc('interests_count')->orderByDesc('published_at'),
            'number' => $q->orderByDesc('number'),
            default => $q->orderByRaw('published_at desc nulls last'),
        };

        $offers = ListView::paginate($request, $q);
        // Кадры нужны плиткам и строкам, в таблице их нет.
        if (! ListView::isTable(ListView::pick($request, $offers->total()))) {
            $offers->loadMissing('media');
        }

        return view('admin.gallery.index', [
            'offers' => $offers,
            'sort' => $sort,
            'facets' => $facets,
        ]);
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user());

        return redirect("/offers/{$offer->number}");
    }
}
