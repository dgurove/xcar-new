<?php

namespace App\Park\Events;

use App\Park\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;

/** ТС стоит дольше порога и не продаётся — раз на машину. */
final class VehicleIdle
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public int $days) {}
}
