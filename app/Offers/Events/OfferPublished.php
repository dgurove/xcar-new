<?php

namespace App\Offers\Events;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class OfferPublished
{
    use Dispatchable;

    public function __construct(public Offer $offer, public ?User $by = null) {}
}
