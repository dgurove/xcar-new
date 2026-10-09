<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Actions\RefundAcquiring;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Actions\AttachServiceCar;
use App\Billing\Actions\ReissueServiceInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Invoice;
use App\Offers\DealCredit;
use App\Offers\Offer;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Ссылки на оплату из CRM: сотрудник заводит ссылку плательщику счёта, отменяет открытую, возвращает деньги по ссылке. */
class PayLinkController
{
    public function store(Request $request, Invoice $invoice, CreatePayLink $create, Gateway $gateway, ReissueServiceInvoice $reissue)
    {
        abort_unless($gateway->configured(), 422, 'Оплата по ссылке не подключена');
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:120'],
        ], ['email.email' => 'Проверьте почту']);
        // Ручная ссылка, по которой не платили, — счёт на новую сумму целиком, а не ссылка на часть.
        if ($invoice->isService() && $invoice->paid == 0 && isset($data['amount']) && ! $invoice->claims()->exists()) {
            $link = $reissue($invoice, $request->user(), (float) $data['amount'])->openLink();

            return redirect($link ? MoneyController::linkUrl($link) : MoneyController::url($invoice))->with('toast', 'Ссылка готова')->with('open-link', $link?->id);
        }
        $party = $invoice->party;
        $link = $create($invoice, $request->user(), isset($data['amount']) ? (float) $data['amount'] : PayLink::defaultAmount($invoice),
            PayerKind::Other, null, $data['name'] ?? $party->name, null, $data['email'] ?? $party->email);

        return back()->with('toast', 'Ссылка готова')->with('open-link', $link->id);
    }

    public function destroy(Request $request, PayLink $link, CancelPayLink $cancel, VoidInvoice $void)
    {
        $invoice = $link->invoice;
        // Ручная без оплат гаснет вместе со счётом: открытый счёт без ссылки — невидимый долг.
        if ($link->isOpen() && $invoice->isService() && $invoice->paid == 0 && ! $invoice->claims()->exists()) {
            $void($invoice, $request->user(), 'Ссылка отменена');
        }
        $cancel($link->fresh(), $request->user());

        return back()->with('toast', 'Ссылка отменена');
    }

    /** Что будет, если привязать ручную ссылку к этой ТС (`DealCredit::preview`) — строка в окне «ТС», без записи. */
    public function attach(Request $request, PayLink $link)
    {
        $offer = ctype_digit((string) $request->query('offer_id')) ? Offer::find((int) $request->query('offer_id')) : null;
        $car = trim((string) $request->query('car_title'));
        $text = $offer || $car === '' ? DealCredit::preview($link->invoice, $offer) : 'ТС не из системы, запишем словами'.($link->invoice->credit_deal_id ? ', зачёт в сделку снимем' : '');

        return response('<p class="text-sm text-ink-muted">'.e($text).'</p>');
    }

    /** ТС у ручной ссылки (`AttachServiceCar`): машина системы — засчитать в её сделку, иначе словами; пусто — отвязать. */
    public function car(Request $request, PayLink $link, AttachServiceCar $attach)
    {
        $data = $request->validate(['offer_id' => ['nullable', 'integer', 'exists:offers,id'], 'car_title' => ['nullable', 'string', 'max:160']]);
        $offer = ! empty($data['offer_id']) && ! $request->boolean('clear') ? Offer::find($data['offer_id']) : null;
        $invoice = $attach($link->invoice, $offer, $request->boolean('clear') ? null : ($data['car_title'] ?? null), $request->user());

        return back()->with('toast', match (true) {
            $request->boolean('clear') => 'ТС снято',
            (bool) $invoice->credit_deal_id => 'Привязано к сделке',
            default => 'Сохранено',
        });
    }

    public function refund(Request $request, AcquiringPayment $attempt, RefundAcquiring $refund)
    {
        try {
            $refund($attempt, $request->user(), $request->boolean('surplus'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return back()->with('toast', 'Не вернули: '.$e->getMessage());
        }

        return back()->with('toast', 'Вернули '.Money::exact($attempt->fresh()->refunded));
    }
}
