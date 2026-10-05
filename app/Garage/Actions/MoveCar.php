<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Перевести машину на другой этап гаража. Менеджер — только вперёд кнопкой своего этапа: «Привёз», «Готова».
 * Сотрудник — между доставкой, подготовкой и продажей в обе стороны (05.10.2026, владелец: «у админа нет возможности
 * в CRM двигать машину по шагам»). «Ждёт страховую» ведёт маршрут сделки, продажу и расчёт — свои действия.
 */
final class MoveCar
{
    public function __invoke(Car $car, CarState $to, User $by): Car
    {
        if ($car->state === $to) {
            throw ValidationException::withMessages(['car' => 'Машина уже на этапе «'.$to->label().'»']);
        }
        if ($by->isAdmin()) {
            if (! $car->state->isWorking() || ! $to->isWorking() || $car->isFrozen()) {
                throw ValidationException::withMessages(['car' => 'С этапа «'.$car->state->label().'» на «'.$to->label().'» так не перевести']);
            }
        } else {
            if ($car->manager_id !== $by->id) {
                throw ValidationException::withMessages(['car' => 'Это не ваша машина']);
            }
            if (($car->state->advance()[0] ?? null) !== $to) {
                throw ValidationException::withMessages(['car' => 'С этапа «'.$car->state->label().'» кнопкой дальше не уйти']);
            }
        }
        $car->moveTo($to);
        GarageChanged::dispatch($car);

        return $car;
    }
}
