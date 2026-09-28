<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLink;
use App\Billing\InvoiceState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Плательщик нажал «Оплатить»: свежая незавершённая попытка — та же страница провайдера, иначе новая
 * на сумму ссылки (или на остаток счёта, если часть уже закрыли иначе). Возвращает, куда перейти.
 * Запрос к провайдеру — вне транзакции: медленная ЮKassa не держит замок ссылки и соединение с базой.
 * Два нажатия разом дадут две попытки — заплатить обе нельзя незаметно: лишнее станет переплатой к возврату.
 */
final class StartCheckout
{
    public function __construct(private Gateway $gateway, private CancelPayLink $cancel) {}

    public function __invoke(PayLink $link): string
    {
        [$amount, $reuse] = DB::transaction(function () use ($link) {
            $link = PayLink::whereKey($link->id)->lockForUpdate()->with('invoice')->firstOrFail();
            if (! $link->isOpen()) {
                throw ValidationException::withMessages(['link' => 'Ссылка '.mb_strtolower($link->state->label())]);
            }
            $invoice = $link->invoice;
            $amount = round(min($link->amount, $invoice->remaining()), 2);
            if ($invoice->state !== InvoiceState::Issued || $amount <= 0) {
                ($this->cancel)($link);

                return [0, null];
            }
            $fresh = $link->attempts()->where('provider', $this->gateway->name())->where('status', 'pending')
                ->where('amount', $amount)->where('created_at', '>', now()->subMinutes(AcquiringPayment::FRESH_MINUTES))->whereNotNull('confirmation_url')->latest('id')->first();

            return [$amount, $fresh?->confirmation_url];
        });
        if ($amount <= 0) {
            throw ValidationException::withMessages(['link' => 'Счёт уже оплачен']);
        }
        if ($reuse) {
            return $reuse;
        }
        $checkout = $this->gateway->create($link->loadMissing('invoice'), $amount, $link->url().'?back=1');
        AcquiringPayment::create([
            'link_id' => $link->id, 'provider' => $this->gateway->name(), 'external_id' => $checkout->id, 'status' => $checkout->status,
            'amount' => $checkout->amount, 'confirmation_url' => $checkout->url, 'payload' => $checkout->raw, 'checked_at' => now(),
        ]);

        return $checkout->url;
    }
}
