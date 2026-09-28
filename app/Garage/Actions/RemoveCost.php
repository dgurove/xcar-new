<?php

namespace App\Garage\Actions;

use App\Garage\Cost;
use App\Garage\Events\GarageChanged;

final class RemoveCost
{
    public function __invoke(Cost $cost): void
    {
        $car = $cost->car;
        $cost->delete();
        GarageChanged::dispatch($car);
    }
}
