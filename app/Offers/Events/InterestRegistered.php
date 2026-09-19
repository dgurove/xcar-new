<?php

namespace App\Offers\Events;

use App\Offers\Interest;
use Illuminate\Foundation\Events\Dispatchable;

final class InterestRegistered
{
    use Dispatchable;

    public function __construct(public Interest $interest) {}
}
