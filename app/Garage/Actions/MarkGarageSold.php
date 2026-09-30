<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Итог продажи вносим мы: за сколько, когда и кому. Вознаграждение — при расчёте, в шторке счёта. */
final class MarkGarageSold
{
    public function __invoke(Car $car, array $data, User $by): Car
    {
        if ($car->isFrozen()) {
            throw ValidationException::withMessages(['sold_price' => 'По ТС выставлен счёт: сначала аннулируйте его']);
        }

        $car->update([
            'sold_price' => $data['sold_price'],
            'sold_at' => $data['sold_at'] ?? now(),
            'buyer_name' => $data['buyer_name'] ?? null,
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'state' => CarState::Sold,
        ]);
        GarageChanged::dispatch($car);

        return $car;
    }
}
