<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Цена продажи и вознаграждение покупателя с гаражом (26.11.2026, владелец: «он не должен нигде видеть цену закупа; цену продажи для
 * того покупателя он назначает сам либо мы за него»; 27.11.2026 по Tiggo: «продаёт за 900, вложил 123 — отнимаем, 50 его комиссия —
 * отнимаем, остальное по счёту на ПРАЙМ»). Цена продажи — `deals.amount`, вознаграждение — `deals.commission`; договор и счёт его
 * клиенту — `Deal::holderNet` (цена − его расходы − вознаграждение). Его цена гаража (`garage_cars.buyer_price`) — наш нижний предел
 * суммы счёта, ему не видна: ниже держателю — отказ без суммы, сотрудник ставит любую.
 */
final class SetGarageSalePrice
{
    public function __construct(private SyncDealInvoices $sync) {}

    public function __invoke(Car $car, int $price, User $by, ?int $commission = null): Car
    {
        $deal = $car->deal;
        if (! $car->forBuyer() || ! $deal) {
            throw ValidationException::withMessages(['sale_price' => 'ТС не у покупателя']);
        }
        if ($car->state === CarState::Settled) {
            throw ValidationException::withMessages(['sale_price' => 'Расчёт закрыт']);
        }
        if ($car->buyerHasPaid()) {
            throw ValidationException::withMessages(['sale_price' => 'По счёту уже есть оплата: цену не поменять']);
        }
        $commission ??= $deal->commission;
        $spent = (int) round($car->extraSpent(\App\Garage\Payer::Manager));
        if ($commission !== null && $commission > $price - $spent) {
            throw ValidationException::withMessages(['sale_commission' => 'Вознаграждение больше, чем остаётся после расходов']);
        }
        if (! $by->garageStaff() && $price - $spent - (int) $commission < (int) $car->buyer_price) {
            throw ValidationException::withMessages(['sale_price' => 'Слишком низкая цена']);
        }
        [$was, $wasCommission] = [$deal->amount, $deal->commission];
        if ($was !== null && (int) $was === $price && $wasCommission === $commission) {
            return $car;
        }
        $deal->update(['amount' => $price, 'commission' => $commission]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Цена продажи '.Money::rub($price).($was !== null && (int) $was !== $price ? ' (была '.Money::rub($was).')' : '')
            .($commission !== null ? ', вознаграждение покупателя '.Money::rub($commission) : '')]);
        // Договор и неоплаченный счёт — на новую цену.
        ($this->sync)($deal->fresh(), $by);
        GarageChanged::dispatch($car);

        return $car;
    }
}
