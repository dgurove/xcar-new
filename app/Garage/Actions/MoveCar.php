<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Перевести машину на другой этап гаража. Менеджер — только вперёд кнопкой своего этапа («Готова»). Сотрудник — между
 * подготовкой, «Готова» и продажей в обе стороны (05.10.2026, владелец: «у админа нет возможности в CRM двигать машину
 * по шагам»). «Ждёт машину» кончает вывоз (`StartPrepOnArrival`), продажу и расчёт — свои действия. В продажу — только
 * когда сделка со страховой закрыта (06.10.2026): до того машина «Готова» и встаёт в продажу сама (`CloseGarageDeal`).
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
        // «Готова» без открытой сделки — сразу в продажу: ждать нечего.
        if ($to === CarState::Ready && ! $car->dealOpen()) {
            $to = CarState::Selling;
        }
        if ($to === CarState::Selling && $car->dealOpen()) {
            throw ValidationException::withMessages(['car' => 'Сначала закроем документы со страховой: в продажу машина встанет сама']);
        }
        $car->moveTo($to, by: $by);
        GarageChanged::dispatch($car);

        return $car;
    }
}
