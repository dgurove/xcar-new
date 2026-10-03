<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Events\GarageChanged;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Следующий этап кнопкой: «Привёз» (доставка → подготовка), «Готова» (подготовка → в продаже). */
final class AdvanceCar
{
    public function __invoke(Car $car, User $by): Car
    {
        $next = $car->state->advance();
        if (! $next) {
            throw ValidationException::withMessages(['car' => 'С этапа «'.$car->state->label().'» кнопкой дальше не уйти']);
        }
        if (! $by->isStaff() && $car->manager_id !== $by->id) {
            throw ValidationException::withMessages(['car' => 'Это не ваша машина']);
        }
        $car->moveTo($next[0]);
        GarageChanged::dispatch($car);

        return $car;
    }
}
