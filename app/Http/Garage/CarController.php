<?php

namespace App\Http\Garage;

use App\Garage\Actions\AddCost;
use App\Garage\Actions\RemoveCost;
use App\Garage\Actions\ReturnFromGarage;
use App\Garage\Actions\UpdateCost;
use App\Garage\Car;
use App\Garage\Cost;
use App\Garage\GarageView;
use App\Garage\Payer;
use App\Http\Cabinet\DealController;
use App\Offers\Actions\PickUp;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Offers\PickupState;
use App\Support\Money;
use App\Support\Surface;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CarController
{
    /** Машины в гараже: менеджеру — свои, сотруднику — все; группами по этапу, внутри — дольше стоящие первыми. */
    public function index(Request $request)
    {
        $cars = Car::of($request->user())
            ->with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'manager', 'costs', 'invoice', 'payoutInvoice', 'deal.openRequirement'])
            ->orderBy('stage_at')
            ->get();

        // Вывозы, порученные менеджеру, — своей группой сверху: ТС у него физически, но продаёт не обязательно он.
        $pickups = Offer::pickupsOf($request->user())
            ->with(['brand', 'model', 'media', 'evacuator', 'positions.stage.exits', 'positions.stage.block'])
            ->get()->sortBy(fn (Offer $o) => [PickupState::awaits($o) ? 0 : 1, $o->position(Track::Service)?->block_entered_at?->timestamp ?? 0])->values();

        return view('garage.cars.index', ['cars' => $cars, 'pickups' => $pickups]);
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

    /** «Забрал» своей машины: менеджер везёт её в свой гараж сам — вывоз отмечается со страницы машины. */
    public function picked(Request $request, Offer $offer, PickUp $pick)
    {
        $pick($this->car($request, $offer)->offer, $request->user());

        return back()->with('toast', 'Забрали');
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
    private function car(Request $request, Offer $offer): Car
    {
        $car = Car::where('offer_id', $offer->id)->with(['offer.brand', 'offer.model', 'offer.media', 'offer.settlement', 'manager', 'costs.author', 'invoice', 'payoutInvoice', 'deal'])->firstOrFail();
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
}
