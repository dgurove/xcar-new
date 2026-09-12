<?php

namespace App\Park\Actions;

use App\Cars\Vin\RememberVin;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;

final class UpdateVehicle
{
    public function __invoke(Vehicle $vehicle, array $data, User $by): Vehicle
    {
        $vehicle->fill($data);
        $changed = array_diff(array_keys($vehicle->getDirty()), ['ref_key']);
        $vehicle->save();
        if ($changed) {
            $vehicle->log(EventType::Updated, $by, ['fields' => array_values($changed)]);
            (new RememberVin)($vehicle);
        }

        return $vehicle;
    }
}
