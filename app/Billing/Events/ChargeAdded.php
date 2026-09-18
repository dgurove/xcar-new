<?php

namespace App\Billing\Events;

use App\Billing\Charge;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class ChargeAdded
{
    use Dispatchable;

    public function __construct(public Charge $charge, public ?User $by = null) {}
}
