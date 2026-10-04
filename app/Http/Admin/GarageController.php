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
 * Машину ведут здесь же, в карточке строки, или в редакторе предложения (карточка «Гараж»); ждущую страховую — в сделке.
 */
class GarageController
{
    public const PRESETS = ['work' => 'В работе', 'sold' => 'Проданы', 'settled' => 'Рассчитались', 'all' => 'Все'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($offer = OfferNumber::find($key)) ? $this->detail($offer) : null);
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

    /** Карточка строки: путь, деньги, расходы и действия сотрудника — как карточка «Гараж» редактора. */
    public function detail(Offer $offer)
    {
        $offer->load(['brand', 'model', 'media', 'positions.stage.block']);
        $view = OfferController::garageView($offer, request()->user());
        abort_unless($view, 404);

        return view('admin.garage.detail', ['garageView' => $view]);
    }
}
