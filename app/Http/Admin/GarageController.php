<?php

namespace App\Http\Admin;

use App\Garage\Car;
use App\Garage\CarLine;
use App\Garage\CarState;
use App\Live\Rows;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Sort;
use Illuminate\Http\Request;
use App\Support\Table\Column;
use App\Support\Table\Table;

/**
 * «Работа → Гараж»: у кого что стоит в гараже и во что обошлось — группами по менеджеру, этап и дни на нём в строке.
 * Машину ведут здесь же, в карточке строки, или в редакторе предложения (карточка «Гараж»); ждущую страховую — в сделке.
 */
class GarageController
{
    public const PRESETS = ['work' => 'В работе', 'sold' => 'Проданы', 'settled' => 'Рассчитались', 'all' => 'Все'];

    /**
     * Внутри группы менеджера: «Этап» — по этапу, потом дольше стоящие; «Дата» — когда машина пришла в гараж; «Дней» — сколько
     * на этапе; «Закупочная» — `Car::price`; «Сумма» — главное число строки (`CarLine`: закупочная, к оплате, цена продажи).
     */
    public const SORTS = ['stage' => ['Этап', 'asc'], 'created' => ['Дата', 'desc'], 'days' => ['Дней на этапе', 'desc'], 'price' => ['Закупочная', 'desc'], 'amount' => ['Сумма', 'desc']];

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
        $sort = Sort::from($request->query('sort'), self::SORTS, 'stage');

        $q = Car::with(['offer', 'offer.media', 'offer.vendor', 'offer.deal', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'offer.parkVehicle', 'offer.pickupTask.executor', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal.offer']);
        match ($preset) {
            'sold' => $q->where('state', CarState::Sold),
            'settled' => $q->where('state', CarState::Settled),
            'all' => $q,
            default => $q->whereIn('state', [CarState::Waiting, CarState::Repair, CarState::Ready, CarState::Selling]),
        };
        $cars = $facets->apply($q)->orderBy('stage_at')->get();
        $total = $cars->count();
        // Живое обновление строкой (`Live\Rows`): машин в гараже десятки — сужаем уже выбранное.
        $cars = Rows::filter($cars, fn (Car $c) => $c->offer?->number);

        // Группы — менеджеры по имени, взятые под себя — последними; внутри — выбранная сортировка.
        $user = $request->user();
        $groups = $cars->sortBy(fn (Car $c) => match ($sort->key) {
            'created' => [$c->created_at?->timestamp ?? 0, $c->id],
            'days' => [$c->stageDays(), $c->id],
            'amount' => [(float) CarLine::for($c, $user, crm: true)->value, $c->id],
            'price' => [(float) $c->price(), $c->id],
            default => [$c->state->order(), $c->stage_at?->timestamp ?? 0],
        }, SORT_REGULAR, $sort->desc)
            ->groupBy(fn (Car $c) => $c->manager_id ?? 0)
            ->sortBy(fn ($rows, $id) => $id ? $rows->first()->manager->name : "\u{FFFF}");

        return view('admin.garage.index', [
            'detail' => $detail,
            'groups' => $groups,
            'total' => $total,
            'preset' => $preset,
            'facets' => $facets,
            'sort' => $sort,
        ]);
    }

    /** Карточка строки: путь, деньги, расходы и действия сотрудника — как карточка «Гараж» редактора. */
    public function detail(Offer $offer)
    {
        $offer->load(['media', 'positions.stage.block']);
        $view = OfferController::garageView($offer, request()->user());
        // «Отдали по ошибке» из этой же карточки: ответ вернулся сюда (DetailBack), а машины в гараже уже нет.
        if (! $view) {
            return view('admin.garage.gone', ['key' => $offer->number]);
        }

        // Письма по машине — как в карточке сделки и предложения (`OfferFiles::letters`): с почтой — тем, у кого она есть.
        return view('admin.garage.detail', ['garageView' => $view] + (request()->user()->canCrmMail() ? OfferFiles::letters($offer, request()->user()) : ['letters' => 0]));
    }

    /**
     * Таблица «Работы → Гаража» — одно описание на шапку, строки `x-admin.garage-row` и заголовки менеджеров. На телефоне
     * вместо денежных столбцов — одно главное число этапа («Сумма»).
     */
    public static function table(): Table
    {
        return Table::of(
            new Column('title', 'Марка, модель', grow: true, cap: false),
            new Column('vendor', 'Вендор, № убытка', from: 'sm', rem: 10),
            new Column('taken', 'В гараже с', from: 'xl', num: true, aside: true, cell: 'nums cell-dim', rem: 5.5),
            new Column('price', 'Закупочная', from: 'sm', num: true, sort: 'price', cell: 'nums', rem: 6.5),
            new Column('spent', 'Расходы', from: 'sm', num: true, cell: 'nums', rem: 5.5),
            new Column('sold', 'Цена продажи', from: 'sm', num: true, cell: 'nums', rem: 7),
            new Column('sum', 'Сумма', num: true, phone: true, cell: 'nums'),
        );
    }
}
