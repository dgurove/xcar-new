<?php

namespace App\Http\Site;

use App\Purchases\Actions\PlaceOffer;
use App\Purchases\Actions\WithdrawOffer;
use App\Purchases\Car;
use App\Purchases\Kind;
use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Purchases\Restriction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Закупка — ведомость: человек проходит машины подряд и по каждой называет цену. Цен поставщика тут нет. */
class PurchaseController
{
    public const PRESETS = ['all' => 'Все', 'mine' => 'С моей ценой', 'none' => 'Без моей цены', 'photos' => 'С фото'];

    public const SORTS = ['dl' => 'По порядку файла', 'fresh' => 'Сначала новые', 'brand' => 'По марке'];

    public function index(Request $request)
    {
        $purchases = Purchase::whereIn('state', [PurchaseState::Open, PurchaseState::Closed])->withCount('cars')->orderByDesc('number')->get();
        $mine = Offer::where('user_id', $request->user()->id)->whereIn('state', [OfferState::Active, OfferState::Chosen])
            ->join('purchase_cars', 'purchase_cars.id', '=', 'purchase_offers.car_id')->selectRaw('purchase_id, count(*) as n')->groupBy('purchase_id')->pluck('n', 'purchase_id');

        return view('site.purchases.index', ['purchases' => $purchases, 'mine' => $mine]);
    }

    public function show(Request $request, Purchase $purchase)
    {
        abort_unless($purchase->state->isPublic(), 404);
        $filters = $request->only(['preset', 'q', 'kind', 'sort']);
        $cars = $this->cars($purchase, $request, $filters)->paginate(40)->withQueryString();
        $total = $this->visible($purchase, $request)->count();
        $done = $this->visible($purchase, $request)->whereHas('offers', fn ($o) => $o->where('user_id', $request->user()->id)->whereIn('state', [OfferState::Active, OfferState::Chosen]))->count();

        return view('site.purchases.show', [
            'purchase' => $purchase, 'cars' => $cars, 'filters' => $filters, 'total' => $total, 'done' => $done,
            'kinds' => $this->visible($purchase, $request)->select('kind')->distinct()->pluck('kind')->map(fn ($k) => Kind::from($k->value ?? $k))->all(),
        ]);
    }

    public function car(Request $request, Purchase $purchase, Car $car)
    {
        abort_unless($purchase->state->isPublic() && $car->purchase_id === $purchase->id && $car->is_published, 404);
        abort_if(in_array($car->kind->value, Restriction::hiddenFor($request->user()), true), 404);
        $car->load(['brand', 'model', 'settlement', 'media', 'offers']);
        $filters = $request->only(['preset', 'q', 'kind', 'sort']);
        // Соседи по тому же отбору; сама машина из отбора не выпадает.
        $ids = $this->cars($purchase, $request, $filters)->pluck('id')->all();
        if (! in_array($car->id, $ids, true)) {
            $ids[] = $car->id;
        }
        $pos = array_search($car->id, $ids, true);
        $prev = $pos > 0 ? Car::find($ids[$pos - 1]) : null;
        $next = $pos < count($ids) - 1 ? Car::find($ids[$pos + 1]) : null;

        return view('site.purchases.car', ['purchase' => $purchase, 'car' => $car, 'mine' => $car->offerOf($request->user()), 'prev' => $prev, 'next' => $next, 'filters' => $filters, 'photos' => $car->visiblePhotos()]);
    }

    public function offer(Request $request, Purchase $purchase, Car $car, PlaceOffer $place)
    {
        abort_unless($car->purchase_id === $purchase->id, 404);
        $data = $request->validate(['amount' => ['required', 'string'], 'comment' => ['nullable', 'string', 'max:500']]);
        $place($car, $request->user(), (int) preg_replace('/\D+/', '', $data['amount']), $data['comment'] ?? null);
        $back = "/zakupki/{$purchase->number}/{$car->ref}".($request->query() ? '?'.http_build_query($request->query()) : '');

        return redirect($back)->with('toast', 'Цена записана');
    }

    public function withdraw(Request $request, Offer $offer, WithdrawOffer $withdraw)
    {
        $withdraw($offer, $request->user());

        return back()->with('toast', 'Цена отозвана');
    }

    private function visible(Purchase $purchase, Request $request): Builder
    {
        return Car::where('purchase_id', $purchase->id)->where('is_published', true)->whereNotIn('kind', Restriction::hiddenFor($request->user()) ?: ['']);
    }

    private function cars(Purchase $purchase, Request $request, array $filters): Builder
    {
        $user = $request->user();
        $q = $this->visible($purchase, $request)->with(['brand', 'model', 'settlement', 'media', 'offers' => fn ($o) => $o->where('user_id', $user->id)]);
        match ($filters['preset'] ?? 'all') {
            'mine' => $q->whereHas('offers', fn ($o) => $o->where('user_id', $user->id)->whereIn('state', [OfferState::Active, OfferState::Chosen])),
            'none' => $q->whereDoesntHave('offers', fn ($o) => $o->where('user_id', $user->id)->whereIn('state', [OfferState::Active, OfferState::Chosen])),
            'photos' => $q->where('photos_count', '>', 0),
            default => null,
        };
        if (! empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower(trim($filters['q'])).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(dl) like ?', [$term])->orWhereRaw('lower(vin) like ?', [$term])->orWhereRaw('lower(brand_raw) like ?', [$term])->orWhereRaw('lower(model_raw) like ?', [$term])
                ->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(name_ru) like ?', [$term])));
        }
        match ($filters['sort'] ?? 'dl') {
            'fresh' => $q->orderByDesc('id'),
            'brand' => $q->orderBy('brand_raw')->orderBy('model_raw'),
            default => $q->orderBy('dl'),
        };

        return $q;
    }
}
