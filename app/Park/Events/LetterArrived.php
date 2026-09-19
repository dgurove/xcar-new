<?php

namespace App\Park\Events;

use App\Mail\Message;
use App\Park\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;

/** Письмо пришло в ветку, привязанную к ТС: переписка по машине продолжается — сотруднику знать. */
final class LetterArrived
{
    use Dispatchable;

    public function __construct(public Vehicle $vehicle, public Message $message) {}
}
