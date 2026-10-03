<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use Illuminate\Validation\ValidationException;

/** «Не продана»: итог снимается, машина снова в продаже. После счёта — только через его аннулирование. */
final class ClearGarageSold
{
    public function __invoke(Car $car): void
    {
        if ($car->invoice_id || $car->state !== CarState::Sold) {
            throw ValidationException::withMessages(['car' => 'По ТС выставлен счёт: сначала аннулируйте его']);
        }

        $car->moveTo(CarState::Selling, ['sold_price' => null, 'sold_at' => null, 'buyer_name' => null, 'buyer_phone' => null, 'commission' => null, 'invoice_to' => null]);
        GarageChanged::dispatch($car);
    }
}
