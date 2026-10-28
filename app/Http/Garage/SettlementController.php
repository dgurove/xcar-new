<?php

namespace App\Http\Garage;

use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentSource;
use App\Garage\Actions\ClearGarageSold;
use App\Garage\Actions\IssueGaragePayout;
use App\Garage\Actions\MarkGarageSold;
use App\Garage\Actions\MoveCar;
use App\Garage\Actions\SetBuyerPrice;
use App\Garage\Actions\SettleGarageCar;
use App\Garage\Car;
use App\Garage\CarState;
use App\Http\Cabinet\PayChoice;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Итог по машине и расчёт с менеджером: продажа, вознаграждение, счёт и оплата. */
class SettlementController
{
    /** «Продана» от сотрудника (с датой и покупателем) или «Продаю» от менеджера — только цена. */
    public function sold(Request $request, Offer $offer, MarkGarageSold $sold)
    {
        $car = $this->car($request, $offer);
        $request->merge(['sold_price' => Money::rubles($request->input('sold_price'))]);
        // Покупатель с гаражом — только отметка, без суммы (20.10.2026).
        $data = $request->validate([
            'sold_price' => $car->forBuyer() ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'sold_at' => ['nullable', 'date', 'before_or_equal:today'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
        ]);
        $sold($car, ['sold_at' => now()] + array_filter($data), $request->user());

        return back()->with('toast', $car->forBuyer() ? 'Продана' : 'Продана за '.Money::rub($data['sold_price']));
    }

    /**
     * Этап машины: «Готова» — следующий кнопкой (менеджер или сотрудник, без `state`); сотрудник — и на любой
     * из подготовки, «Готова», продажи, назад тоже.
     */
    public function stage(Request $request, Offer $offer, MoveCar $move)
    {
        $car = $this->car($request, $offer);
        $data = $request->validate(['state' => ['nullable', Rule::enum(CarState::class)]]);
        $to = CarState::tryFrom($data['state'] ?? '') ?? $car->state->advance()[0] ?? $car->state;

        // «Готова» без открытой сделки встаёт сразу в продажу — в тосте то, где машина оказалась.
        return back()->with('toast', $move($car, $to, $request->user())->state->label());
    }

    public function unsold(Request $request, Offer $offer, ClearGarageSold $clear)
    {
        $clear($this->staffCar($request, $offer), $request->user());

        return back()->with('toast', 'Не продана');
    }

    /** Цена покупателю с гаражом — правит сотрудник, пока за счёт не платили (`SetBuyerPrice`). */
    public function buyerPrice(Request $request, Offer $offer, SetBuyerPrice $set)
    {
        $car = $this->staffCar($request, $offer);
        $request->merge(['buyer_price' => Money::rubles($request->input('buyer_price'))]);
        $price = $request->validate(['buyer_price' => ['required', 'integer', 'min:1']], ['buyer_price.required' => 'Цена для покупателя'])['buyer_price'];
        $set($car, $price, $request->user());

        return back()->with('toast', 'Цена покупателю '.Money::rub($price));
    }

    /** Счёт с вознаграждением: платит менеджер (как раньше) или его покупатель — плательщик, как у сделки. */
    public function settle(Request $request, Offer $offer, SettleGarageCar $settle)
    {
        $car = $this->staffCar($request, $offer);
        $data = $request->validate([
            'commission' => ['nullable', 'integer', 'min:0'],
            'payer' => ['nullable', 'in:manager,buyer'],
            'party_id' => ['nullable', Rule::when(fn () => ! in_array($request->input('party_id'), [null, '', 'new'], true), ['exists:billing_parties,id'])],
            'party_name' => [Rule::requiredIf(fn () => $request->input('payer') === 'buyer' && $request->input('party_id') === 'new'), 'nullable', 'string', 'max:255'],
            'party_kind' => ['nullable', Rule::enum(PartyKind::class)],
            'party_inn' => ['nullable', 'string', 'max:12'],
            'party_phone' => ['nullable', 'string', 'max:32'],
        ], ['party_name.required' => 'Кто платит: название или ФИО']);
        $buyer = null;
        if (($data['payer'] ?? 'manager') === 'buyer') {
            $buyer = ($data['party_id'] ?? 'new') === 'new'
                ? ['kind' => PartyKind::tryFrom($data['party_kind'] ?? '') ?? PartyKind::Person, 'name' => $data['party_name'], 'inn' => $data['party_inn'] ?? null, 'phone' => $data['party_phone'] ?? null]
                : Party::findOrFail($data['party_id']);
        }
        $invoice = $settle($car, $request->user(), isset($data['commission']) ? (int) $data['commission'] : null, $buyer);

        return back()->with('toast', $invoice ? 'Счёт '.$invoice->label() : 'Расчёт закрыт');
    }

    /** Деньги пришли — отмечает сотрудник; заявку менеджера подтверждаем ею же. */
    public function pay(Request $request, Offer $offer, RecordPayment $record)
    {
        $car = $this->staffCar($request, $offer);
        // Покупатель уже оплатил — дальше ждёт выплата менеджеру: «Выплатили» отмечает её.
        $invoice = $car->payoutInvoice ?? $car->invoice ?? $car->holderInvoice();
        abort_unless($invoice, 404);
        $request->merge(['amount' => Money::parse($request->input('amount'))]);
        // Поля — общей формы «Деньги пришли» (`x-billing.pay-form`, 28.10.2026): откуда, номер и скан платёжки, заметка.
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'source' => ['nullable', \Illuminate\Validation\Rule::enum(PaymentSource::class)],
            'ref' => ['exclude_unless:source,bank,acquiring', 'nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'slip' => ['exclude_unless:source,bank', 'nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);
        $claim = $invoice->claims()->first();
        $source = isset($data['source']) ? PaymentSource::from($data['source']) : ($claim?->source ?? PaymentSource::Bank);
        $payment = $record($invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, $source, $data['ref'] ?? null, $data['note'] ?? null, $claim);
        if (isset($data['slip'])) {
            $payment->addMediaFromRequest('slip')->toMediaCollection('slip');
        }

        return back()->with('toast', 'Поступило '.Money::exact($data['amount']));
    }

    /** «Оплатить» у менеджера: ссылкой, по счёту или наличными — та же шторка, что в кабинете. */
    public function checkout(Request $request, Offer $offer, PayChoice $choice)
    {
        $car = $this->car($request, $offer);
        abort_unless($car->invoice && ! $car->invoice->isOwed() && $car->manager_id === $request->user()->id, 404);
        [$toast, $link] = $choice($request, $car->invoice, $request->user());

        return back()->with('toast', $toast)->with('open-link', $link?->id);
    }

    /** Новая ссылка на счёт машины: без суммы — на остаток, с суммой — на неё; прежняя гаснет. */
    public function link(Request $request, Offer $offer, CreatePayLink $create)
    {
        $car = $this->car($request, $offer);
        $invoice = $car->invoice;
        abort_unless($invoice && ! $invoice->isOwed() && $car->manager_id === $request->user()->id, 404);
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01']]);
        $party = $invoice->party;
        $link = $create($invoice, $request->user(), isset($data['amount']) ? (float) $data['amount'] : PayLink::defaultAmount($invoice), PayerKind::Other, null, $party->name, $party->phone, $party->email);

        return back()->with('toast', 'Ссылка готова')->with('open-link', $link->id);
    }

    public function cancelLink(Request $request, Offer $offer, PayLink $link, CancelPayLink $cancel)
    {
        $car = $this->car($request, $offer);
        abort_unless($link->invoice_id === $car->invoice_id, 404);
        $cancel($link, $request->user());

        return back()->with('toast', 'Ссылка отменена');
    }

    /** Аннулировать открытый документ: выплату менеджеру, если покупатель уже заплатил, иначе сам счёт. */
    public function voidInvoice(Request $request, Offer $offer, VoidInvoice $void)
    {
        $car = $this->staffCar($request, $offer);
        $payout = $car->payoutInvoice?->state === InvoiceState::Void ? null : $car->payoutInvoice;
        $document = $payout ?? $car->invoice;
        abort_unless($document, 404);
        $void($document, $request->user(), $request->input('reason'));

        return back()->with('toast', $payout ? 'Выплата аннулирована' : 'Счёт аннулирован');
    }

    /** Выплата менеджеру заново: покупатель заплатил, а выплаты нет (аннулировали или оплата пришла без автора). */
    public function payout(Request $request, Offer $offer, IssueGaragePayout $issue)
    {
        $car = $this->staffCar($request, $offer);
        $commission = $request->validate(['commission' => ['nullable', 'integer', 'min:0']])['commission'] ?? null;
        $invoice = $issue($car, $request->user(), $commission !== null ? (int) $commission : null);

        return back()->with('toast', $invoice ? 'К выплате '.Money::exact($invoice->remaining()) : 'Расчёт закрыт');
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
        $car = Car::where('offer_id', $offer->id)->with(['offer', 'manager', 'costs', 'invoice.payments', 'payoutInvoice', 'deal'])->firstOrFail();
        abort_unless($request->user()->isAdmin() || $car->manager_id === $request->user()->id, 404);

        return $car;
    }

    private function staffCar(Request $request, Offer $offer): Car
    {
        abort_unless($request->user()->isAdmin(), 403);

        return $this->car($request, $offer);
    }
}
