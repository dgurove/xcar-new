<?php

namespace App\Http\Admin;

use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Track;
use Illuminate\Http\Request;

/**
 * «Работа → Вывоз» (04.10.2026): кто что вывозит — группами по ответственному («Мы», «Иван Петров»), в строке шаг
 * вывоза словом и дни. Карточка строки — путь вывоза с кнопками и «Кто и куда вывозит»; страница — редактор предложения.
 * Это не гараж: ТС у менеджера, а продаётся обычным путём.
 */
class PickupController
{
    public const PRESETS = ['work' => 'В работе', 'standing' => 'Стоят', 'all' => 'Все'];

    /** Внутри группы: «На шаге» — дольше всех на шаге вывоза сверху; «Дата» — когда заведено предложение. */
    public const SORTS = ['step' => ['На шаге', 'desc'], 'created' => ['Дата', 'desc']];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($offer = OfferNumber::find($key)) ? $this->detail($offer) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-pickups',
            Common::manager('offers.evacuator_id', 'who', 'Кто вывозит')->none('Мы'),
            Facet::column('to', 'Куда', ['место', 'места', 'мест'], 'offers.evacuation_to')->enum(Destination::class),
        );
        ListPrefs::sync($request, 'crm-pickups', keep: $facets->keys());
        $preset = array_key_exists($request->query('preset'), self::PRESETS) ? $request->query('preset') : 'work';
        $sort = Sort::from($request->query('sort'), self::SORTS, '-step');

        // Только назначенные вывозы: у вендоров с автозапуском позиция вывоза есть у каждого предложения, а кто и куда
        // везёт, выбирают руками (`AssignPickup`).
        $q = Offer::whereNotNull('evacuation_to')->whereHas('positions', fn ($p) => $p->where('track', Track::Service))
            ->whereNotIn('state', [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived])
            ->with(['brand', 'model', 'media', 'vendor', 'evacuator', 'positions.stage.exits', 'positions.stage.block']);
        $offers = $facets->apply($q)->get()
            // Стоят — дошли до конца вывоза (у этапа нет выходов), в работе — остальные.
            ->filter(fn (Offer $o) => match ($preset) {
                'standing' => $o->position(Track::Service)->stage->exits->isEmpty(),
                'all' => true,
                default => $o->position(Track::Service)->stage->exits->isNotEmpty(),
            })
            // «На шаге» по убыванию — дольше всех (вошли раньше) сверху.
            ->sortBy(fn (Offer $o) => $sort->key === 'created' ? $o->id : -($o->position(Track::Service)->block_entered_at?->timestamp ?? 0), SORT_REGULAR, $sort->desc);

        // Группы — ответственные по имени, «Мы» — последней.
        $groups = $offers->groupBy(fn (Offer $o) => $o->evacuator_id ?? 0)
            ->sortBy(fn ($rows, $id) => $id ? $rows->first()->evacuator->name : "\u{FFFF}");

        return view('admin.pickups.index', [
            'detail' => $detail,
            'groups' => $groups,
            'total' => $offers->count(),
            'preset' => $preset,
            'facets' => $facets,
            'sort' => $sort,
        ]);
    }

    public function detail(Offer $offer)
    {
        $offer->load(['brand', 'model', 'media', 'vendor.workflows', 'evacuator', 'parkVehicle.requests', 'positions.stage.block', 'positions.stage.exits.to', 'positions.stage.workflow']);
        abort_unless($offer->position(Track::Service), 404);

        return view('admin.pickups.detail', [
            'offer' => $offer,
            'managers' => User::withRole(Role::Manager)->orderBy('name')->get(),
        ]);
    }
}
