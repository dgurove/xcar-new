<?php

namespace App\Http\Site;

use App\Cars\Brand;
use App\Offers\CatalogQuery;
use App\Offers\OfferState;
use Illuminate\Http\Request;

class CatalogController
{
    public function index(Request $request)
    {
        $filters = $request->only(['brand', 'q', 'open', 'sort']);

        return view('site.catalog', [
            'offers' => CatalogQuery::for($request->user(), $filters)->paginate(24)->withQueryString(),
            'filters' => $filters,
            'brands' => Brand::whereHas('offers', fn ($o) => $o->whereIn('state', [OfferState::Open, OfferState::Closed]))->orderBy('name')->get(),
        ]);
    }
}
