<?php

namespace App\Park\Events;

use App\Mail\Message;
use App\Park\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;

/** Страховая написала, что ТС продано и его заберут: пора готовить выдачу. */
final class VehicleSold
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public ?Message $message = null) {}
}
