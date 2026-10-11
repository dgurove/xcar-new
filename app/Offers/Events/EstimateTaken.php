<?php

namespace App\Offers\Events;

use App\Offers\Estimate;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Оценку взяли ценой продажи. */
final class EstimateTaken
{
    use Dispatchable;

    public function __construct(public Estimate $estimate, public ?User $by = null) {}
}
