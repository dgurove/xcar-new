<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ссылка на оплату счёта: сумма — не больше остатка за вычетом заявленного, плательщик — сам менеджер,
 * его покупатель или человек по имени и телефону (телефон нужен чеку). Прежняя открытая ссылка счёта гаснет.
 */
final class CreatePayLink
{
    public function __construct(private CancelPayLink $cancel) {}

    public function __invoke(Invoice $invoice, User $by, float $amount, PayerKind $kind, ?User $payer = null, ?string $name = null, ?string $phone = null, ?string $email = null): PayLink
    {
        return DB::transaction(function () use ($invoice, $by, $amount, $kind, $payer, $name, $phone, $email) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            // Вознаграждение от поставщика платит вендор по счёту, не человек по ссылке.
            if ($invoice->state !== InvoiceState::Issued || $invoice->isOwed() || $invoice->kind === ChargeKind::Reward) {
                throw ValidationException::withMessages(['amount' => 'Счёт '.mb_strtolower($invoice->state->label())]);
            }
            $left = round($invoice->remaining() - $invoice->claimed(), 2);
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $left + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Не больше '.Money::exact(max(0, $left))]);
            }
            [$name, $phone, $email] = match ($kind) {
                PayerKind::Self => [$by->name, $by->phone, $by->email],
                PayerKind::Buyer => [$payer?->name, $payer?->phone, $payer?->email],
                PayerKind::Other => [$name, $phone, $email],
            };
            if (! $phone && ! $email) {
                throw ValidationException::withMessages(['payer_phone' => 'Нужен телефон плательщика: на него придёт чек']);
            }
            PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->get()->each(fn (PayLink $old) => ($this->cancel)($old, $by));
            $link = PayLink::create([
                'code' => PayLink::freshCode(), 'invoice_id' => $invoice->id, 'amount' => $amount, 'payer_kind' => $kind,
                'payer_user_id' => $kind === PayerKind::Buyer ? $payer?->id : null, 'payer_name' => $name, 'payer_phone' => $phone, 'payer_email' => $email,
                'state' => PayLinkState::Open, 'created_by' => $by->id,
            ]);
            $invoice->offer?->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::exact($amount).' по счёту '.$invoice->label().', платит '.$link->payerLabel()]);

            return $link;
        });
    }
}
