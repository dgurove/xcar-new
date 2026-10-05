<?php

namespace App\Billing\Acquiring\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLink;
use App\Billing\InvoiceState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Плательщик нажал «Оплатить»: свежая незавершённая попытка — та же страница провайдера, иначе новая
 * на сумму ссылки (или на остаток счёта, если часть уже закрыли иначе). Возвращает, куда перейти.
 * Запрос к провайдеру — вне транзакции: медленная ЮKassa не держит замок ссылки и соединение с базой.
 * Повтор с тем же телом в получасовом окне ЮKassa узнаёт по ключу идемпотентности и отдаёт тот же платёж — строку
 * попытки находим по его id (`AcquiringPayment::adopt`). Почту для чека и имя, если их не знали при выставлении,
 * вводит сам плательщик на `/pay` — они пишутся в ссылку до запроса.
 */
final class StartCheckout
{
    public function __construct(private Gateway $gateway, private CancelPayLink $cancel) {}

    public function __invoke(PayLink $link, ?string $name = null, ?string $email = null): string
    {
        [$amount, $reuse] = DB::transaction(function () use ($link, $name, $email) {
            $link = PayLink::whereKey($link->id)->lockForUpdate()->with('invoice')->firstOrFail();
            if (! $link->isOpen()) {
                throw ValidationException::withMessages(['link' => 'Ссылка '.mb_strtolower($link->state->label())]);
            }
            $invoice = $link->invoice;
            // Демо-кабинет для проверяющих: страница оплаты настоящая, а платёж по ней — нет.
            if ($invoice->is_demo) {
                throw ValidationException::withMessages(['link' => 'Оплата сейчас недоступна']);
            }
            $amount = round(min($link->amount, $invoice->remaining()), 2);
            if ($invoice->state !== InvoiceState::Issued || $amount <= 0) {
                ($this->cancel)($link);

                return [0, null];
            }
            $link->fill(array_filter(['payer_name' => trim((string) $name) ?: null, 'payer_email' => trim((string) $email) ?: null]));
            if (! filter_var((string) $link->payer_email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email' => 'Нужна почта: на неё придёт чек']);
            }
            $link->save();
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
        $checkout = $this->gateway->create($link->fresh('invoice'), $amount, $link->url().'?back=1');
        AcquiringPayment::adopt($checkout, $this->gateway->name());
        if (! $checkout->url) {
            throw new RuntimeException('ЮKassa не дала страницу оплаты для '.$checkout->id.' ('.$checkout->status.')');
        }
        $link->update(['error' => null, 'error_at' => null]);

        return $checkout->url;
    }
}
