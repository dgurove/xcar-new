<?php

namespace App\Garage\Actions;

use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Users\User;

final class RemoveCost
{
    public function __invoke(Cost $cost, ?User $by = null): void
    {
        $car = $cost->car;
        $cost->delete();
        $car->log($by, ['do' => 'cost_removed', 'title' => $cost->title, 'amount' => $cost->amount]);
        GarageChanged::dispatch($car);
    }
}
