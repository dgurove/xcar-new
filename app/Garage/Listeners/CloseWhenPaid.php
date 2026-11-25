<?php

namespace App\Garage\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Events\PaymentRecorded;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Offers\Deal;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;

/**
 * Счёт по машине оплачен — менеджером или его покупателем — расчёт закрыт: состояние ставится само, руками отмечать нечего
 * (выплат менеджеру после оплаты покупателя нет с 18.11.2026).
 */
final class CloseWhenPaid
{
    public function handle(PaymentRecorded $e): void
    {
        if ($e->invoice->state !== InvoiceState::Paid) {
            return;
        }

        $car = Car::where('state', CarState::Sold)->where('invoice_id', $e->invoice->id)->first();
        // Покупатель с гаражом оплатил свой счёт (счёт сделки), а ТС уже продана — расчёт закрыт (20.10.2026).
        $car ??= $e->invoice->deal_id ? Car::where('state', CarState::Sold)->where('deal_id', $e->invoice->deal_id)->first() : null;
        $car ??= self::soldToClient($e);
        if ($car && ($car->invoice_id === $e->invoice->id || $car->buyerPaid())) {
            $car->moveTo(CarState::Settled, ['settled_at' => now()], $e->by);
            GarageChanged::dispatch($car);
        }
    }

    /**
     * Покупатель с гаражом нашёл своего клиента, и клиент оплатил счёт на себя (26.11.2026, 779934/2026: Ананьев продал Voyah
     * Гречишкину, деньги пришли по выписке, а ТС так и стояла «Продаю»): это и есть продажа — «Продана» ставится сама, дальше
     * расчёт закрывается тем же путём. Счёт на самого держателя — нет: он платит свою цену, продал ли — не наше. Покупка у
     * поставщика ещё не закрыта (`papersPending`) — не трогаем: продать раньше нельзя и руками.
     */
    private static function soldToClient(PaymentRecorded $e): ?Car
    {
        $invoice = $e->invoice;
        $deal = $invoice->kind === ChargeKind::Sale && $invoice->deal_id ? Deal::with('buyer')->find($invoice->deal_id) : null;
        if (! $deal?->forGarageBuyer() || $invoice->party_id === Party::forUser($deal->buyer, create: false)->id) {
            return null;
        }
        $car = Car::where('deal_id', $deal->id)->first();
        if (! $car || ! $car->state->isWorking() || $car->papersPending()) {
            return null;
        }
        $car->moveTo(CarState::Sold, ['sold_at' => now(), 'buyer_name' => $car->buyer_name ?? $invoice->party?->name], $e->by, ['client' => $invoice->party?->name]);

        return $car;
    }
}
