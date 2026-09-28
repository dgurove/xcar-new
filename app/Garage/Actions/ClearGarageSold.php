<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use Illuminate\Validation\ValidationException;

/** «Не продана»: итог снимается, машина снова чинится. После счёта — только через его аннулирование. */
final class ClearGarageSold
{
    public function __invoke(Car $car): void
    {
        if ($car->invoice_id) {
            throw ValidationException::withMessages(['car' => 'По машине выставлен счёт: сначала аннулируйте его']);
        }

        $car->update(['sold_price' => null, 'sold_at' => null, 'buyer_name' => null, 'buyer_phone' => null, 'commission' => null, 'state' => CarState::Repair]);
    }
}
