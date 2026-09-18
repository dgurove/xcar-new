<?php

namespace App\Park\Events;

use App\Park\Request;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class VehicleAccepted
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public ?Request $request = null, public ?User $by = null) {}
}
