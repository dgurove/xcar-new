<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\GarageSelling;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Продана. Менеджер жмёт «Продаю» из «В продаже» и пишет только цену — её не отклоняем, нам в Telegram:
 * назначить вознаграждение и выставить счёт. Сотрудник вносит итог с любого рабочего этапа, как раньше.
 */
final class MarkGarageSold
{
    public function __invoke(Car $car, array $data, User $by): Car
    {
        if ($car->isFrozen()) {
            throw ValidationException::withMessages(['sold_price' => 'По ТС выставлен счёт: сначала аннулируйте его']);
        }
        $staff = $by->isStaff();
        if ($staff ? ! $car->state->isWorking() : ($car->state !== CarState::Selling || $car->manager_id !== $by->id)) {
            throw ValidationException::withMessages(['sold_price' => 'Продать можно машину в продаже']);
        }

        $car->moveTo(CarState::Sold, [
            'sold_price' => $data['sold_price'],
            'sold_at' => $data['sold_at'] ?? now(),
            'buyer_name' => $staff ? ($data['buyer_name'] ?? null) : null,
            'buyer_phone' => $staff ? ($data['buyer_phone'] ?? null) : null,
        ]);
        GarageChanged::dispatch($car);
        if (! $staff) {
            NotifyOwner::dispatch(new GarageSelling($car->load(['offer.brand', 'offer.model', 'manager', 'costs'])));
        }

        return $car;
    }
}
