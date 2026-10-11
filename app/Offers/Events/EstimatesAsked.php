<?php

namespace App\Offers\Events;

use App\Offers\Estimate;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/** Админ отдал черновик менеджерам на оценку (11.10.2026): только новые просьбы, уже открытые повторно не шлются. */
final class EstimatesAsked
{
    use Dispatchable;

    /** @param  Collection<int, Estimate>  $estimates */
    public function __construct(public Offer $offer, public Collection $estimates, public ?User $by = null) {}
}
