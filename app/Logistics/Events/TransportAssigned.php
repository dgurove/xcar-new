<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Задаче назначили исполнителя или логиста: ему — уведомление; прежнему поручение снято молча. */
final class TransportAssigned
{
    use Dispatchable;

    public function __construct(public Transport $transport, public ?User $previous = null) {}
}
