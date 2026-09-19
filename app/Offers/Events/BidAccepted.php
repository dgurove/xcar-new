<?php

namespace App\Offers\Events;

use App\Offers\Bid;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class BidAccepted
{
    use Dispatchable;

    public function __construct(public Bid $bid, public ?User $by = null) {}
}
