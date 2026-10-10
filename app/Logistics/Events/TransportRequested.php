<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use Illuminate\Foundation\Events\Dispatchable;

/** Перевозка между регионами отправлена в логистику без исполнителя: логисту — назначить. */
final class TransportRequested
{
    use Dispatchable;

    public function __construct(public Transport $transport) {}
}
