<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\Events\GarageChanged;
use App\Offers\CarPlace;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Ещё не вывезли» (20.10.2026, владелец: «рука дрогнула, нажали не то, когда сделку начинали — как шаг назад сделать»):
 * обратное к «Уже у нас» / «Уже у менеджера» при «Отдать» в гараж. Машина снова у владельца, подготовка — «Ждёт ТС»,
 * вывоз — в логистике (прежняя отменённая задача открывается снова, `EnsurePickup` → `OpenPickup`). Обратное к ошибке в
 * другую сторону — «Уже вывезли» в дорожке «Вывоз» (`Logistics\Actions\Backdate`); привезённое вывозом откатывают его
 * «Отменить «Привёз»» (`Logistics\Actions\StepBack`), не здесь. Только сотрудник и пока расходов нет: подготовка не шла.
 */
final class UndoArrival
{
    public function __construct(private SetCarPlace $place, private EnsurePickup $ensure) {}

    public static function allows(Car $car): bool
    {
        $offer = $car->offer;

        return $car->state === CarState::Repair && ! $car->isFrozen() && $car->costs->isEmpty()
            && ! $offer->pickup()?->state->picked()
            && in_array($offer->car_place, [CarPlace::Keeper, CarPlace::WithUs], true)
            && $offer->parkVehicle?->state !== VehicleState::Stored;
    }

    public function __invoke(Car $car, User $by): Car
    {
        if (! $by->isAdmin() || ! self::allows($car)) {
            throw ValidationException::withMessages(['car' => 'ТС уже готовят или везут: так не вернуть']);
        }
        DB::transaction(function () use ($car, $by) {
            ($this->place)($car->offer, CarPlace::Owner, $by);
            $car->moveTo(CarState::Waiting, by: $by);
            ($this->ensure)($car->offer->fresh(), $by);
        });
        GarageChanged::dispatch($car);

        return $car;
    }
}
