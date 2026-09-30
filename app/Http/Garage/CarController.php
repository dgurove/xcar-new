<?php

namespace App\Http\Garage;

use App\Garage\Actions\AddCost;
use App\Garage\Actions\RemoveCost;
use App\Garage\Actions\ReturnFromGarage;
use App\Garage\Actions\UpdateCost;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Cost;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Http\Request;

class CarController
{
    /** Машины в гараже: менеджеру — свои, сотруднику — все; чинящиеся первыми. */
    public function index(Request $request)
    {
        $cars = Car::of($request->user())
            ->with(['offer.brand', 'offer.model', 'offer.media', 'manager', 'costs', 'invoice'])
            ->orderByRaw('case when state = ? then 0 else 1 end', [CarState::Repair->value])
            ->orderByDesc('taken_at')
            ->get();

        return view('garage.cars.index', ['cars' => $cars]);
    }

    public function show(Request $request, Offer $offer)
    {
        return view('garage.cars.show', ['car' => $this->car($request, $offer)]);
    }

    public function storeCost(Request $request, Offer $offer, AddCost $add)
    {
        $car = $this->car($request, $offer);
        abort_if($car->isSold() && ! $request->user()->isStaff(), 403);
        $data = $this->costData($request);
        $add($car, $data, $request->user());

        return back()->with('toast', 'Записано '.Money::exact($data['amount']));
    }

    public function updateCost(Request $request, Cost $cost, UpdateCost $update)
    {
        $this->car($request, $cost->car->offer);
        abort_unless($cost->editableBy($request->user()), 403);
        $update($cost, $this->costData($request));

        return back()->with('toast', 'Сохранено');
    }

    public function destroyCost(Request $request, Cost $cost, RemoveCost $remove)
    {
        $this->car($request, $cost->car->offer);
        abort_unless($cost->editableBy($request->user()), 403);
        $remove($cost);

        return back()->with('toast', 'Расход убран');
    }

    /** «Отдали по ошибке»: только сотрудник и только пока ничего не записано. */
    public function destroy(Request $request, Offer $offer, ReturnFromGarage $return)
    {
        abort_unless($request->user()->isStaff(), 403);
        $return($this->car($request, $offer), $request->user());

        return redirect('/garage')->with('toast', 'ТС вернулось в черновики');
    }

    /** Машина этого человека или любая — сотруднику; чужая для менеджера не существует. */
    private function car(Request $request, Offer $offer): Car
    {
        $car = Car::where('offer_id', $offer->id)->with(['offer.brand', 'offer.model', 'offer.media', 'manager', 'costs.author', 'invoice'])->firstOrFail();
        $car->costs->each->setRelation('car', $car);
        abort_unless($request->user()->isStaff() || $car->manager_id === $request->user()->id, 404);

        return $car;
    }

    private function costData(Request $request): array
    {
        $request->merge(['amount' => Money::parse($request->input('amount'))]);

        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'spent_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }
}
