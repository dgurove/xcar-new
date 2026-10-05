<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Actions\RefundAcquiring;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Invoice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Ссылки на оплату из CRM: сотрудник заводит ссылку плательщику счёта, отменяет открытую, возвращает деньги по ссылке. */
class PayLinkController
{
    public function store(Request $request, Invoice $invoice, CreatePayLink $create, Gateway $gateway)
    {
        abort_unless($gateway->configured(), 422, 'Оплата по ссылке не подключена');
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:120'],
        ], ['email.email' => 'Проверьте почту']);
        $party = $invoice->party;
        $link = $create($invoice, $request->user(), isset($data['amount']) ? (float) $data['amount'] : PayLink::defaultAmount($invoice),
            PayerKind::Other, null, $data['name'] ?? $party->name, null, $data['email'] ?? $party->email);

        return back()->with('toast', 'Ссылка готова')->with('open-link', $link->id);
    }

    public function destroy(Request $request, PayLink $link, CancelPayLink $cancel)
    {
        $cancel($link, $request->user());

        return back()->with('toast', 'Ссылка отменена');
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
