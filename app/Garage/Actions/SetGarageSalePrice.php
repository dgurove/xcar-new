<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Events\GarageChanged;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Цена продажи у покупателя с гаражом (24.11.2026, владелец: «вознаграждение как у менеджера»; «2,7 млн — это цена продажи минус
 * вознаграждение, закуп плюс подбор; что свыше — он возьмёт себе; пусть введёт цену продажи, чтобы как в сделке было»). Его цена
 * (`garage_cars.buyer_price`) — что он платит нам счётом и сумма договора, она не меняется; цена продажи — сколько платит его
 * клиент всего (`deals.amount`), вознаграждение — разница (`deals.commission`), его берёт он сам, мимо нас. Ниже его цены — нельзя.
 */
final class SetGarageSalePrice
{
    public function __invoke(Car $car, int $price, User $by): Car
    {
        $deal = $car->deal;
        if (! $car->forBuyer() || ! $deal) {
            throw ValidationException::withMessages(['sale_price' => 'ТС не у покупателя']);
        }
        $base = (int) $car->buyer_price;
        if ($price < $base) {
            throw ValidationException::withMessages(['sale_price' => 'Меньше цены '.Money::rub($base).': вознаграждение не может быть в минус']);
        }
        $was = $deal->amount;
        $deal->update(['amount' => $price, 'commission' => $price - $base]);
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Цена продажи '.Money::rub($price).($was !== null && (int) $was !== $price ? ' (была '.Money::rub($was).')' : '')
            .', вознаграждение '.Money::rub($price - $base)]);
        GarageChanged::dispatch($car);

        return $car;
    }

    /** Цена покупателю сменилась — вознаграждение снова разница с ценой продажи (`SetBuyerPrice`). */
    public static function follow(Car $car): void
    {
        $deal = $car->deal;
        if ($car->forBuyer() && $deal?->amount !== null) {
            $deal->update(['commission' => max(0, (int) $deal->amount - (int) $car->buyer_price)]);
        }
    }
}
