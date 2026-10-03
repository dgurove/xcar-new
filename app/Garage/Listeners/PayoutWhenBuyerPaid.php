<?php

namespace App\Garage\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Garage\Actions\IssueGaragePayout;
use App\Garage\Car;
use App\Garage\CarState;
use Illuminate\Support\Facades\Log;

/**
 * Покупатель оплатил машину из гаража — выплата менеджеру (`IssueGaragePayout`). Оплата пришла сама (эквайринг,
 * выписка) — документ оформляет тот, кто завёл машину в гараж; не от кого — выплату заведёт сотрудник кнопкой.
 */
final class PayoutWhenBuyerPaid
{
    public function __construct(private IssueGaragePayout $payout) {}

    public function handle(PaymentRecorded $e): void
    {
        if ($e->invoice->state !== InvoiceState::Paid) {
            return;
        }
        $car = Car::with('author')->where('invoice_id', $e->invoice->id)->where('invoice_to', 'buyer')->where('state', CarState::Sold)->first();
        if (! $car) {
            return;
        }
        $by = $e->by ?? $car->author;
        if (! $by) {
            Log::warning("Гараж: выплату по машине {$car->id} заводит сотрудник — оплата пришла без автора");

            return;
        }
        ($this->payout)($car, $by);
    }
}
