<?php

namespace App\Http\Admin;

use App\Garage\Car;
use App\Garage\CarLine;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Live\Rows;
use App\Offers\CarWindow;
use App\Offers\OfferFilters;
use App\Offers\OfferNumber;
use App\Offers\OfferTabs;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\Request;

/**
 * «Гараж» — свой раздел колеса «Предложений» (16.11.2026; начальник: «в гараже всего 5 машин»; владелец: «как в галерее, все машины,
 * несмотря на то что они в других этапах», «разбивку на этапы, как во «Все»»): все гаражные машины до расчёта, группами по этапу
 * сделки (`OfferTabs`: «Менеджер должен нам», «Мы должны поставщику»…), сделка закрыта — по этапу гаража («Сделка закрыта:
 * подготовка»); менеджер — столбцом и чипом. Расчёт закрыт — в «Завершённых». «Работы → Гаража» нет: `/work/garage` — 301.
 */
class GarageController
{
    /**
     * Внутри группы менеджера: «Этап» — по этапу, потом дольше стоящие; «Дата» — когда машина пришла в гараж; «Дней» — сколько
     * на этапе; «Закупочная» — `Car::price`; «Сумма» — главное число строки (`CarLine`: закупочная, к оплате, цена продажи).
     */
    public const SORTS = ['stage' => ['Этап', 'asc'], 'created' => ['Дата', 'desc'], 'days' => ['Дней на этапе', 'desc'], 'price' => ['Закупочная', 'desc'], 'amount' => ['Сумма', 'desc']];

    /** Прежний адрес «Работы → Гаража» (уведомления, закладки) — во вкладку «В гараже», с открытой машиной. */
    public function index(Request $request)
    {
        return redirect('/offers?'.http_build_query(array_filter(['preset' => 'garage', 'peek' => $request->query('peek')])), 301);
    }

    public function tab(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        // Фильтры (16.11.2026, владелец: «фильтры хорошие»): этап сделки (группы вкладки), менеджер, этап гаража, кто платит
        // поставщику, вендор.
        // Шапка вкладки (17.11.2026): все чипы и параметры машины, денег и сроков (`OfferFilters`) — в окне «Фильтр»; «Во вкладке» —
        // с входа в этап гаража.
        $facets = Facets::for($request, 'crm-garage',
            Facet::column('tab', 'Состояние', ['состояние', 'состояния', 'состояний'], OfferTabs::sql('garage_cars.offer_id'))->natural()->icon('layers')
                ->labels(fn (array $keys) => collect([...OfferTabs::keys(), OfferTabs::OTHER])->filter(fn ($k) => in_array($k, $keys, true))
                    ->mapWithKeys(fn ($k) => [$k => new Option($k, self::tabLabel($k))])->all()),
            Common::manager('garage_cars.manager_id')->none('Взяли под себя'),
            Common::vendor('(select o.vendor_id from offers o where o.id = garage_cars.offer_id)'),
            Facet::column('stage', 'Этап гаража', ['этап', 'этапа', 'этапов'], 'garage_cars.state')->enum(CarState::class)->natural()->icon('warehouse'),
            Facet::column('payer', 'Кто платит поставщику', ['вариант', 'варианта', 'вариантов'], '(select d.garage_payer from deals d where d.id = garage_cars.deal_id)')
                ->enum(GaragePayer::class)->natural()->icon('hand-coins'),
            ...OfferFilters::for('garage_cars.offer_id', $request->user()->canManageCrm(), 'coalesce(garage_cars.stage_at, garage_cars.taken_at)'),
        )->saving('offers');
        ListPrefs::sync($request, 'crm-garage', keep: array_values(array_diff($facets->keys(), OfferController::ONCE)));
        $sort = Sort::from($request->query('sort'), self::SORTS, 'stage');

        // Все гаражные машины до расчёта, в любом этапе сделки (16.11.2026, начальник: «всего 5 машин»).
        $q = Car::with(['offer', 'offer.media', 'offer.vendor', 'offer.deal', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'offer.parkVehicle', 'offer.pickupTask.executor', 'manager', 'manager.media', 'costs', 'invoice', 'payoutInvoice', 'deal.offer'])
            ->where('state', '!=', CarState::Settled)->whereHas('offer');
        // Поиск лупой (17.11.2026: у остальных вкладок колеса он есть) — по машине, мимо чипов, как у предложений.
        if ($facets->searching()) {
            $q->whereHas('offer', fn ($o) => $o->searchCrm(trim((string) $request->query('q'))));
        }
        $cars = $facets->apply($q)->orderBy('stage_at')->get();
        $total = $cars->count();
        // Живое обновление строкой (`Live\Rows`): машин в гараже десятки — сужаем уже выбранное.
        $cars = Rows::filter($cars, fn (Car $c) => $c->offer?->number);

        // Группы — этап сделки, как во «Все» (`OfferTabs`, порядок колеса); сделка закрыта — по этапу гаража. Внутри — выбранная
        // сортировка.
        $user = $request->user();
        $order = array_flip(OfferTabs::keys());
        $groups = $cars->sortBy(fn (Car $c) => match ($sort->key) {
            'created' => [$c->created_at?->timestamp ?? 0, $c->id],
            'days' => [$c->stageDays(), $c->id],
            'amount' => [(float) CarLine::for($c, $user, crm: true)->value, $c->id],
            'price' => [(float) $c->price(), $c->id],
            default => [$c->state->order(), $c->stage_at?->timestamp ?? 0],
        }, SORT_REGULAR, $sort->desc)
            ->groupBy(fn (Car $c) => self::groupKey($c))
            ->sortBy(fn ($rows, string $key) => [$order[strtok($key, ':')] ?? 999, $rows->first()->state->order()]);

        return view('admin.garage.index', [
            'detail' => $detail,
            'groups' => $groups,
            'statuses' => CarLine::crmStatuses($cars),
            'total' => $total,
            'facets' => $facets,
            'sort' => $sort,
            // Колесо — вкладки «Предложений» с разделами (как у вкладок сделки).
            'presets' => OfferController::presets($user),
            'sections' => OfferController::sections($user),
            'counts' => OfferController::counts($user),
        ]);
    }

    /** Группа машины: вкладка сделки (`OfferTabs`), сделка закрыта — `garage:<этап гаража>`. */
    public static function groupKey(Car $car): string
    {
        $tab = $car->offer ? OfferTabs::of($car->offer) : OfferTabs::OTHER;

        return $tab === 'garage' ? 'garage:'.$car->state->value : $tab;
    }

    /**
     * Заголовок группы: этап сделки словом вкладки, после сделки — этап гаража («Подготовка», «В продаже»; 17.11.2026 без
     * «Сделка закрыта:» — владелец: «очень непонятные описания»).
     */
    public static function groupTitle(string $key): string
    {
        return str_starts_with($key, 'garage:') ? CarState::from(substr($key, 7))->label() : self::tabLabel($key);
    }

    /** Подпись этапа в чипе: вкладка сделки, «Гараж» здесь — «Сделка закрыта». */
    private static function tabLabel(string $key): string
    {
        return $key === 'garage' ? 'Сделка закрыта' : OfferTabs::label($key);
    }

    /**
     * Таблица «Гаража» — одно описание на шапку, строки `x-admin.garage-row` и заголовки групп. На телефоне
     * вместо денежных столбцов — одно главное число этапа («Сумма»).
     */
    public static function table(): Table
    {
        return Table::of(
            // Название — шириной в самое длинное (`title-cap`), остальное — «Этапу» (29.10.2026).
            new Column('title', 'Марка, модель', grow: true),
            new Column('vendor', '№ убытка', from: 'sm', role: ColRole::Code, rem: 10),
            // Этап — своим столбцом от 640 (29.10.2026), как «Что сейчас» в «Сделках»; был вторым этажом названия.
            new Column('stage', 'Этап', from: 'sm', role: ColRole::State, rem: 11),
            // Менеджер — столбцом (16.11.2026: группы теперь по этапу сделки, а не по менеджеру); «под себя» — словом.
            new Column('manager', 'Менеджер', from: 'sm', cell: 'whitespace-nowrap', role: ColRole::Who, rem: 11),
            new Column('taken', 'В гараже с', from: 'xl', aside: true, cell: 'nums', role: ColRole::When, rem: 5.5),
            new Column('price', 'Закупочная', from: 'sm', num: true, sort: 'price', cell: 'nums', rem: 6.5),
            new Column('spent', 'Расходы', from: 'sm', num: true, cell: 'nums', rem: 5.5),
            new Column('sold', 'Цена продажи', from: 'sm', num: true, cell: 'nums', rem: 7),
            new Column('sum', 'Сумма', num: true, phone: true, cell: 'nums'),
        );
    }
}
