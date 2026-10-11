<?php

namespace App\Offers\Events;

use App\Offers\Estimate;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Менеджер оценил (или поправил оценку) — админам карточка со звуком. */
final class EstimateGiven
{
    use Dispatchable;

    public function __construct(public Estimate $estimate, public ?User $by = null) {}
}
