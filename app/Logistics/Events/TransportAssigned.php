<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Перевозке назначили исполнителя (водителя или партнёра): ему — уведомление; прежнему поручение снято молча. */
final class TransportAssigned
{
    use Dispatchable;

    public function __construct(public Transport $transport, public ?User $previous = null) {}
}
