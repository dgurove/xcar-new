<?php

namespace App\Offers\Events;

use App\Offers\Bid;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class BidPlaced
{
    use Dispatchable;

    public function __construct(public Bid $bid, public ?User $by = null) {}
}
