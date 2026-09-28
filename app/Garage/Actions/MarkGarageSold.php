<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Payer;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Итог по машине вносим мы: за сколько продана и сколько из этого — менеджеру.
 * Деньги покупателя у него на руках, поэтому он оставляет себе свои расходы и
 * вознаграждение, а остальное отдаёт нам.
 */
final class MarkGarageSold
{
    public function __invoke(Car $car, array $data, User $by): Car
    {
        $base = round($data['sold_price'] - $car->spent(Payer::Manager), 2);
        if ($car->manager && ($data['commission'] ?? 0) > $base) {
            throw ValidationException::withMessages(['commission' => 'Вознаграждение больше, чем менеджер нам отдаёт']);
        }

        $car->update([
            'sold_price' => $data['sold_price'],
            'sold_at' => $data['sold_at'] ?? now(),
            'buyer_name' => $data['buyer_name'] ?? null,
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'commission' => $data['commission'] ?? null,
            'state' => CarState::Sold,
        ]);

        return $car;
    }
}
