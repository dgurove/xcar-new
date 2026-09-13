<?php

namespace App\Http\Admin;

use App\Offers\Actions\CreateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\ListPrefs;
use Illuminate\Http\Request;

/** Галерея — предложения «скоро в продаже»: без цены, копят интерес. Новое заводится черновиком, в галерею — с карточки. */
class GalleryController
{
    public const SORTS = ['fresh' => 'Сначала новые', 'interest' => 'По интересу', 'number' => 'По номеру'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'crm-gallery');
        $sort = $request->query('sort', 'fresh');
        $q = Offer::query()->where('state', OfferState::Gallery)->with(['brand', 'model', 'media'])->withCount(['activeBids', 'interests']);
        if ($term = trim((string) $request->query('q'))) {
            $q->search($term);
        }
        match ($sort) {
            'interest' => $q->orderByDesc('interests_count')->orderByDesc('published_at'),
            'number' => $q->orderByDesc('number'),
            default => $q->orderByRaw('published_at desc nulls last'),
        };

        return view('admin.gallery.index', [
            'offers' => $q->paginate(24)->withQueryString(),
            'sort' => $sort,
        ]);
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user());

        return redirect("/predlozheniya/{$offer->number}");
    }
}
