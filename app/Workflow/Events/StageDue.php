<?php

namespace App\Workflow\Events;

use App\Offers\Offer;
use App\Workflow\Position;
use Illuminate\Foundation\Events\Dispatchable;

/** Срок этапа подходит (overdue=false) или вышел (overdue=true). */
final class StageDue
{
    use Dispatchable;

    public function __construct(public Offer $offer, public Position $position, public bool $overdue) {}
}
