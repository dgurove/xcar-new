<?php

namespace App\Http\Admin;

use App\Garage\Car;
use App\Garage\CarState;
use App\Offers\Offer;
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
        $preset = array_key_exists($request->query('preset'), self::PRESETS) ? $request->query('preset') : 'work';
        $stage = CarState::tryFrom((string) $request->query('stage'));

        $cars = Car::with(['offer.brand', 'offer.model', 'offer.media', 'offer.vendor', 'offer.positions.stage.block', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal'])
            ->when($stage, fn ($c) => $c->where('state', $stage))
            ->when(! $stage, fn ($c) => match ($preset) {
                'sold' => $c->where('state', CarState::Sold),
                'settled' => $c->where('state', CarState::Settled),
                'all' => $c,
                default => $c->whereIn('state', [CarState::Waiting, CarState::Delivery, CarState::Repair, CarState::Selling]),
            })
            ->orderBy('stage_at')->get();

        // Группы — менеджеры по имени, взятые под себя — последними; внутри — по этапу, потом дольше стоящие.
        $groups = $cars->sortBy(fn (Car $c) => [$c->state->order(), $c->stage_at?->timestamp ?? 0])
            ->groupBy(fn (Car $c) => $c->manager_id ?? 0)
            ->sortBy(fn ($rows, $id) => $id ? $rows->first()->manager->name : "\u{FFFF}");

        return view('admin.garage.index', [
            'groups' => $groups,
            'total' => $cars->count(),
            'preset' => $preset,
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
