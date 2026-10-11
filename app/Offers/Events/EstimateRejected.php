<?php

namespace App\Offers\Events;

use App\Offers\Estimate;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Админ отклонил оценку — менеджер оценивает снова, срок прежний. */
final class EstimateRejected
{
    use Dispatchable;

    public function __construct(public Estimate $estimate, public ?User $by = null) {}
}
