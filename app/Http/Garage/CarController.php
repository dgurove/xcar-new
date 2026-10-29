<?php

namespace App\Http\Garage;

use App\Garage\Actions\AddCost;
use App\Garage\Actions\RemoveCost;
use App\Garage\Actions\ReturnFromGarage;
use App\Garage\Actions\UndoArrival;
use App\Garage\Actions\UpdateCost;
use App\Garage\Car;
use App\Garage\Cost;
use App\Garage\GarageView;
use App\Garage\Payer;
use App\Http\Cabinet\DealController;
use App\Logistics\Actions\RequestPickup;
use App\Logistics\Actions\SelfPickup;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Support\Money;
use App\Support\Sort;
use App\Support\Surface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Table\Column;
use App\Support\Table\Table;

class CarController
{
    /** «Этап» — где ход менеджера первыми, дальше по пути, дольше стоящие выше; «Дата» — когда машина пришла в гараж. */
    public const SORTS = ['stage' => ['Этап', 'asc'], 'fresh' => ['Дата', 'desc'], 'days' => ['Дней на этапе', 'desc'], 'price' => ['Закупочная', 'desc'], 'amount' => ['Сумма', 'desc']];

    /** Машины в гараже: менеджеру — свои, сотруднику — все; группами по этапу, внутри — дольше стоящие первыми. */
    public function index(Request $request)
    {
        $cars = Car::of($request->user())
            ->with(['offer', 'offer.media', 'offer.vendor', 'offer.deal', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'offer.parkVehicle', 'offer.pickupTask.executor', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal.openRequirement', 'deal.offer'])
            ->orderBy('stage_at')
            ->get();

        // Стоят у менеджера без его сделки (`held_by`, 21.10.2026): чинить не стал, продаёт XCar — забирать будут у него.
        $user = $request->user();
        $held = Offer::whereNotNull('held_by')->when(! $user->isAdmin(), fn ($q) => $q->where('held_by', $user->id))
            ->with(['media', 'heldBy'])->orderBy('id')->get();

        return view('garage.cars.index', [
            'cars' => $cars,
            'held' => $held,
            // Закупочной у менеджера нет (15.10.2026) — и сортировки по ней.
            'sort' => Sort::from($request->query('sort'), $request->user()->isAdmin() ? self::SORTS : array_diff_key(self::SORTS, ['price' => 1]), 'stage'),
        ]);
    }

    public function show(Request $request, Offer $offer)
    {
        $car = $this->car($request, $offer);
        // Сделка со страховой идёт — шаг сделки и путь, как на её странице: просьбы менеджеру («Забираю», «Отказываюсь») — тут.
        // Сотрудник ведёт маршрут в CRM — ему шаг не нужен.
        $step = $car->dealOpen() && ! $request->user()->isAdmin() ? DealController::stepData($car->deal) : null;

        return view('garage.cars.show', [
            'view' => GarageView::for($car, $request->user(), $step),
            'photos' => $offer->visiblePhotos(),
            'docs' => OfferFiles::forManagers($offer, $request->user()),
        ]);
    }

    public function storeCost(Request $request, Offer $offer, AddCost $add)
    {
        $car = $this->car($request, $offer);
        $data = $this->costData($request);
        $add($car, $data, $request->user());

        return back()->with('toast', 'Записано '.Money::exact($data['amount']));
    }

    public function updateCost(Request $request, Cost $cost, UpdateCost $update)
    {
        $this->car($request, $cost->car->offer);
        abort_unless($cost->editableBy($request->user()), 403);
        $update($cost, $this->costData($request), $request->user());

        return back()->with('toast', 'Сохранено');
    }

    public function destroyCost(Request $request, Cost $cost, RemoveCost $remove)
    {
        $this->car($request, $cost->car->offer);
        abort_unless($cost->editableBy($request->user()), 403);
        $remove($cost, $request->user());

        return back()->with('toast', 'Расход убран');
    }

    /** «Отдали по ошибке»: только сотрудник и только пока ничего не записано. */
    public function destroy(Request $request, Offer $offer, ReturnFromGarage $return)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $return($this->car($request, $offer), $request->user());

        // Из CRM «Отдали по ошибке» жмут в редакторе предложения — туда и возвращаемся.
        return redirect(Surface::current() === Surface::Crm ? '/offers/'.$offer->number : '/garage')->with('toast', 'ТС вернулось в черновики');
    }

    /** Машина этого человека или любая — сотруднику; чужая для менеджера не существует. */
    /** «Забрал» — менеджер забрал машину сам: вывоз закрыт «у него», подготовка начнётся сама (`SelfPickup`). */
    public function picked(Request $request, Offer $offer, SelfPickup $self)
    {
        $car = $this->car($request, $offer);
        abort_unless($car->manager_id === $request->user()->id && $car->isWaiting(), 404);
        if ($hold = $car->deal?->isActive() ? $car->deal->pickupHold() : null) {
            return back()->with('toast-danger', 'Забирать рано: '.$hold);
        }
        $self($offer, $request->user(), $request->user());

        return back()->with('toast', 'Забрал: '.$offer->titleWithYear());
    }

    /** «Нужен вывоз» — просит логистов (`RequestPickup`). */
    public function requestPickup(Request $request, Offer $offer, RequestPickup $pickup)
    {
        $car = $this->car($request, $offer);
        abort_unless($car->manager_id === $request->user()->id && $car->isWaiting(), 404);
        $pickup($offer, $request->user());

        return back()->with('toast', 'Попросили вывоз, логистика назначит исполнителя');
    }

    /** «Ещё не вывезли» — при «Отдать» ошиблись «Уже у нас»: снова ждёт ТС, вывоз в логистике (`UndoArrival`). */
    public function notArrived(Request $request, Offer $offer, UndoArrival $undo)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $undo($this->car($request, $offer), $request->user());

        return back()->with('toast', 'Ждёт ТС, вывоз в логистике');
    }

    private function car(Request $request, Offer $offer): Car
    {
        $car = Car::where('offer_id', $offer->id)->with(['offer', 'offer.media', 'offer.settlement', 'manager', 'costs.author', 'invoice', 'payoutInvoice', 'deal'])->firstOrFail();
        $car->costs->each->setRelation('car', $car);
        abort_unless($request->user()->isAdmin() || $car->manager_id === $request->user()->id, 404);

        return $car;
    }

    private function costData(Request $request): array
    {
        $request->merge(['amount' => Money::parse($request->input('amount'))]);

        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'spent_at' => ['nullable', 'date', 'before_or_equal:today'],
            // Кто платил — выбирает только сотрудник (`AddCost`, `UpdateCost`); от менеджера поле не берётся.
            'paid_by' => ['nullable', Rule::enum(Payer::class)],
        ]);
    }

    /**
     * Таблица гаража на сайте — одно описание на шапку, строки `x-garage.table-row` и заголовки групп. Сотруднику — ещё
     * «Менеджер»; подпись цены — какую видит держатель (покупателю с гаражом — его цена или цена продажи).
     */
    public static function table(\App\Users\User $user, bool $staff): Table
    {
        return Table::of(
            // Название — шириной в самое длинное (`title-cap`), остальное — «Этапу» (29.10.2026).
            new Column('title', 'Марка, модель', grow: true),
            $staff ? new Column('manager', 'Менеджер', from: 'md', dim: true, rem: 8) : null,
            // Этап — своим столбцом от 640 (29.10.2026), а не вторым этажом названия.
            new Column('stage', 'Этап', from: 'sm', fill: true, rem: 11),
            new Column('taken', 'В гараже с', from: 'lg', num: true, aside: true, cell: 'nums cell-dim', rem: 5.5),
            new Column('price', $user->holdsAsBuyer() ? ($user->seesGaragePrice() ? 'Цена' : 'Цена продажи') : 'Закупочная', from: 'sm', num: true, sort: 'price', cell: 'nums', rem: 7),
            new Column('spent', 'Расходы', from: 'sm', num: true, cell: 'nums', rem: 5.5),
            new Column('sold', 'Цена продажи', from: 'sm', num: true, cell: 'nums', rem: 7),
            new Column('sum', 'Сумма', num: true, phone: true, cell: 'nums'),
        );
    }
}
