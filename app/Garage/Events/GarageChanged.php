<?php

namespace App\Garage\Events;

use App\Garage\Car;
use Illuminate\Foundation\Events\Dispatchable;

/** По машине в гараже что-то изменилось: расход, итог, счёт. Экраны гаража перечитываются сами. */
final class GarageChanged
{
    use Dispatchable;

    public function __construct(public Car $car) {}
}
