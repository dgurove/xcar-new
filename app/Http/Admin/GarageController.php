<?php

namespace App\Http\Admin;

use App\Garage\Car;
use App\Garage\CarState;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use Illuminate\Http\Request;

/**
 * «Работа → Гараж»: у кого что стоит в гараже и во что обошлось — группами по менеджеру, этап и дни на нём в строке.
 * Машину ведут на её карточке (сайт, `/garage/cars/{n}`), ждущую страховую — на странице сделки в CRM.
 */
class GarageController
{
    public const PRESETS = ['work' => 'В работе', 'sold' => 'Проданы', 'settled' => 'Рассчитались', 'all' => 'Все'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($offer = OfferNumber::find($key)) ? $this->peek($offer) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-garage',
            Common::manager('garage_cars.manager_id')->none('Взяли под себя'),
            Facet::column('stage', 'Этап', ['этап', 'этапа', 'этапов'], 'garage_cars.state')->enum(CarState::class)->natural(),
        );
        ListPrefs::sync($request, 'crm-garage', keep: $facets->keys());
        $preset = array_key_exists($request->query('preset'), self::PRESETS) ? $request->query('preset') : 'work';

        $q = Car::with(['offer.brand', 'offer.model', 'offer.media', 'offer.vendor', 'offer.positions.stage.block', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal']);
        match ($preset) {
            'sold' => $q->where('state', CarState::Sold),
            'settled' => $q->where('state', CarState::Settled),
            'all' => $q,
            default => $q->whereIn('state', [CarState::Waiting, CarState::Delivery, CarState::Repair, CarState::Selling]),
        };
        $cars = $facets->apply($q)->orderBy('stage_at')->get();

        // Группы — менеджеры по имени, взятые под себя — последними; внутри — по этапу, потом дольше стоящие.
        $groups = $cars->sortBy(fn (Car $c) => [$c->state->order(), $c->stage_at?->timestamp ?? 0])
            ->groupBy(fn (Car $c) => $c->manager_id ?? 0)
            ->sortBy(fn ($rows, $id) => $id ? $rows->first()->manager->name : "\u{FFFF}");

        return view('admin.garage.index', [
            'detail' => $detail,
            'groups' => $groups,
            'total' => $cars->count(),
            'preset' => $preset,
            'facets' => $facets,
        ]);
    }

    /** Окошко строки: путь, деньги и расходы машины. */
    public function peek(Offer $offer)
    {
        $car = Car::with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal'])
            ->where('offer_id', $offer->id)->firstOrFail();

        return view('admin.garage.peek', ['car' => $car]);
    }
}
