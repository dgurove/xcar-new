<?php

namespace App\Offers\Events;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class OfferPublished
{
    use Dispatchable;

    /** `slot` — вышло слотом: «Новое предложение» тогда уходит одним на слот (`NotifyViewers::due`), а не сразу. */
    public function __construct(public Offer $offer, public ?User $by = null, public bool $slot = false) {}
}
