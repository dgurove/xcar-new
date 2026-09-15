<?php

namespace App\Purchases\Actions;

use App\Cars\Vin\RememberVin;
use App\Media\Jobs\StampPhotos;
use App\Purchases\Car;

/** Правка машины руками: изменённые поля запираются от следующей выкачки. */
final class UpdateCar
{
    public function __invoke(Car $car, array $data): Car
    {
        $car->fill($data);
        $locked = array_unique([...($car->locked_fields ?? []), ...array_keys($car->getDirty())]);
        $car->locked_fields = array_values(array_diff($locked, ['locked_fields', 'is_published', 'description', 'share_locked']));
        $car->save();
        if ($car->wasChanged('share_locked')) {
            StampPhotos::dispatch($car);
        }
        (new RememberVin)($car);

        return $car;
    }
}
