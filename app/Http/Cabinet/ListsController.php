<?php

namespace App\Http\Cabinet;

use App\Http\Site\OfferController;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Sort;
use Illuminate\Http\Request;

class ListsController
{
    public const FAVORITE_SORTS = ['added' => ['Дата добавления', 'desc'], 'price' => ['Цена', 'asc'], 'published' => ['Дата публикации', 'desc'], 'closing' => ['Закрытие приёма', 'asc']];

    public function favorites(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($offer = OfferNumber::find($key)) && $offer->isVisibleTo($request->user()) ? app(OfferController::class)->detail($request, $offer) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        ListPrefs::sync($request, 'favorites');
        $sort = Sort::from($request->query('sort'), self::FAVORITE_SORTS, '-added');
        $dir = $sort->dir();
        // Избранное — только то, что человеку и сейчас видно: закрытое менеджером покупателю не показываем.
        $offers = Offer::with(['brand', 'model', 'media', 'favorites'])
            ->whereHas('favorites', fn ($f) => $f->where('user_id', $request->user()->id))
            ->when($request->user()->isBuyer(), fn ($q) => $q->visibleTo($request->user())->with(['interests' => fn ($i) => $i->where('user_id', $request->user()->id)]));
        // Добавлено — когда отметил человек, а не когда заведено предложение (было `latest()` по предложению).
        match ($sort->key) {
            'price' => $offers->orderByRaw("asking_price {$dir} nulls last"),
            'published' => $offers->orderByRaw("published_at {$dir} nulls last"),
            'closing' => $offers->orderByRaw("bids_close_at {$dir} nulls last"),
            default => $offers->orderByRaw('(select max(f.created_at) from favorites f where f.offer_id = offers.id and f.user_id = ?) '.$dir, [$request->user()->id]),
        };
        $offers->orderByDesc('offers.id');

        return view('cabinet.favorites', ['offers' => ListView::paginate($request, $offers), 'detail' => $detail, 'sort' => $sort]);
    }

    public function interests(Request $request)
    {
        $user = $request->user();
        $sort = Sort::from($request->query('sort'), ['fresh' => ['Дата', 'desc']], '-fresh');
        $interests = Interest::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $user->id)->orderBy('created_at', $sort->dir())->orderBy('id', $sort->dir())->paginate(30);
        // Что из отмеченного всё ещё открыто человеку: проданное и закрытое остаётся в списке с пометкой, но без перехода.
        $live = Offer::whereIn('id', $interests->pluck('offer_id'))->visibleTo($user)->pluck('id')->all();

        return view('cabinet.interests', ['interests' => $interests, 'live' => $live, 'manager' => $user->isBuyer() ? $user->manager : null, 'sort' => $sort]);
    }
}
