<?php

namespace App\Offers\Events;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Назначили, кто вывозит ТС и куда: менеджеру — уведомление, спискам вывоза — обновиться. */
final class PickupAssigned
{
    use Dispatchable;

    public function __construct(public Offer $offer, public ?User $previous = null, public ?User $by = null) {}
}
