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
 * Ссылка на оплату счёта: сумма — не больше остатка за вычетом заявленного и не больше лимита одного платежа
 * (СБП и SberPay — 700 000 ₽), плательщик — сам менеджер, его покупатель или человек по имени. Почта для чека —
 * из профиля или введённая; не знаем — её введёт плательщик на `/pay` (чек ЮKassa приходит только на почту).
 * Прежняя открытая ссылка счёта гаснет. Ссылку, заведённую вместе со счётом (`EnsurePayLink`), в историю
 * предложения не пишем: её никто не делал.
 */
final class CreatePayLink
{
    public function __construct(private CancelPayLink $cancel) {}

    public function __invoke(Invoice $invoice, User $by, float $amount, PayerKind $kind, ?User $payer = null, ?string $name = null, ?string $phone = null, ?string $email = null, bool $auto = false): PayLink
    {
        return DB::transaction(function () use ($invoice, $by, $amount, $kind, $payer, $name, $phone, $email, $auto) {
            $invoice = Invoice::withoutGlobalScope('demo')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            // Вознаграждение от поставщика платит вендор по счёту, не человек по ссылке.
            if ($invoice->state !== InvoiceState::Issued || $invoice->isOwed() || $invoice->kind === ChargeKind::Reward) {
                throw ValidationException::withMessages(['amount' => 'Счёт '.mb_strtolower($invoice->state->label())]);
            }
            $left = round($invoice->remaining() - $invoice->claimed(fresh: true), 2);
            $amount = round($amount, 2);
            if ($amount <= 0 || $amount > $left + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Не больше '.Money::exact(max(0, $left))]);
            }
            $max = (float) config('xcar.yookassa.max_amount');
            if ($max > 0 && $amount > $max + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Не больше '.Money::exact($max).' за один платёж, остальное второй ссылкой или по счёту']);
            }
            [$name, $phone, $email] = match ($kind) {
                PayerKind::Self => [$by->name, $by->phone, $by->email ?: $email],
                PayerKind::Buyer => [$payer?->name, $payer?->phone, $payer?->email ?: $email],
                PayerKind::Other => [$name, $phone, $email],
            };
            if (filled($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email' => 'Проверьте почту: на неё придёт чек']);
            }
            PayLink::where('invoice_id', $invoice->id)->where('state', PayLinkState::Open)->get()->each(fn (PayLink $old) => ($this->cancel)($old, $by));
            $link = PayLink::create([
                'code' => PayLink::freshCode(), 'invoice_id' => $invoice->id, 'amount' => $amount, 'payer_kind' => $kind,
                'payer_user_id' => $kind === PayerKind::Buyer ? $payer?->id : null, 'payer_name' => $name, 'payer_phone' => $phone, 'payer_email' => $email,
                'state' => PayLinkState::Open, 'created_by' => $by->id,
            ]);
            if ($auto) {
                return $link;
            }
            $invoice->offer?->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::exact($amount).' по счёту '.$invoice->label().', платит '.$link->payerLabel()]);

            return $link;
        });
    }
}
