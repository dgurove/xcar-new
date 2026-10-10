<?php

namespace App\Logistics\Events;

use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Исполнитель забрал или сдал машину, перевозку отменили: тому, кто отправил в логистику, — строкой в ленте. */
final class TransportMoved
{
    use Dispatchable;

    public function __construct(public Transport $transport, public User $by) {}
}
