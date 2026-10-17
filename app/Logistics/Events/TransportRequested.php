<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use Illuminate\Foundation\Events\Dispatchable;

/** Новый вывоз без исполнителя (письмо Совкомбанка, «Вывезти» в CRM, гараж): админам — выбрать, кто вывозит. */
final class TransportRequested
{
    use Dispatchable;

    public function __construct(public Transport $transport) {}
}
