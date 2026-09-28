<?php

namespace App\Http\Garage;

use App\Billing\Actions\ClaimPayment;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\PaymentSource;
use App\Garage\Actions\ClearGarageSold;
use App\Garage\Actions\MarkGarageSold;
use App\Garage\Actions\SettleGarageCar;
use App\Garage\Car;
use App\Garage\CarState;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Итог по машине и расчёт с менеджером: продажа, вознаграждение, счёт и оплата. */
class SettlementController
{
    /** Расчёты: машины, где деньги уже считаются. Менеджеру — свои. */
    public function index(Request $request)
    {
        $cars = Car::of($request->user())->whereIn('state', [CarState::Sold, CarState::Settled])
            ->with(['offer.brand', 'offer.model', 'manager', 'costs', 'invoice'])
            ->orderByDesc('sold_at')->get();

        return view('garage.money.index', ['cars' => $cars]);
    }

    public function sold(Request $request, Offer $offer, MarkGarageSold $sold)
    {
        $car = $this->staffCar($request, $offer);
        $data = $request->validate([
            'sold_price' => ['required', 'integer', 'min:1'],
            'sold_at' => ['nullable', 'date', 'before_or_equal:today'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
            'commission' => ['nullable', 'integer', 'min:0'],
        ]);
        $sold($car, $data + ['sold_at' => now()], $request->user());

        return back()->with('toast', 'Продана за '.Money::rub($data['sold_price']));
    }

    public function unsold(Request $request, Offer $offer, ClearGarageSold $clear)
    {
        $clear($this->staffCar($request, $offer));

        return back()->with('toast', 'Не продана');
    }

    public function settle(Request $request, Offer $offer, SettleGarageCar $settle)
    {
        $car = $this->staffCar($request, $offer);
        $invoice = $settle($car, $request->user(), $request->boolean('vat'));

        return back()->with('toast', $invoice ? 'Счёт '.$invoice->label() : 'Расчёт закрыт');
    }

    /** Деньги пришли — отмечает сотрудник; заявку менеджера подтверждаем ею же. */
    public function pay(Request $request, Offer $offer, RecordPayment $record)
    {
        $car = $this->staffCar($request, $offer);
        abort_unless($car->invoice, 404);
        $request->merge(['amount' => Money::parse($request->input('amount'))]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $record($car->invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, PaymentSource::Bank, null, null, $car->invoice->claims()->first());

        return back()->with('toast', 'Поступило '.Money::exact($data['amount']));
    }

    /** Менеджер сообщает об оплате: сумма, дата и платёжка. */
    public function claim(Request $request, Offer $offer, ClaimPayment $claim)
    {
        $car = $this->car($request, $offer);
        abort_unless($car->invoice && $car->manager_id === $request->user()->id, 404);
        $request->merge(['amount' => Money::parse($request->input('amount'))]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'slip' => ['required', 'file', 'max:16384'],
        ]);
        $claim($car->invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, $request->file('slip'));

        return back()->with('toast', 'Сообщили об оплате');
    }

    public function voidInvoice(Request $request, Offer $offer, VoidInvoice $void)
    {
        $car = $this->staffCar($request, $offer);
        abort_unless($car->invoice, 404);
        $void($car->invoice, $request->user(), $request->input('reason'));

        return back()->with('toast', 'Счёт аннулирован');
    }

    /** PDF счёта — сотруднику и хозяину машины; на сайте свой адрес, но туда из гаража не ходят. */
    public function pdf(Request $request, Offer $offer)
    {
        $car = $this->car($request, $offer);
        $media = $car->invoice?->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    private function car(Request $request, Offer $offer): Car
    {
        $car = Car::where('offer_id', $offer->id)->with(['offer', 'manager', 'costs', 'invoice.payments'])->firstOrFail();
        abort_unless($request->user()->isStaff() || $car->manager_id === $request->user()->id, 404);

        return $car;
    }

    private function staffCar(Request $request, Offer $offer): Car
    {
        abort_unless($request->user()->isStaff(), 403);

        return $this->car($request, $offer);
    }
}
