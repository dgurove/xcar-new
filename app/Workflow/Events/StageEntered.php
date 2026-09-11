<?php

namespace App\Workflow\Events;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Events\Dispatchable;

final class StageEntered
{
    use Dispatchable;

    public function __construct(
        public Offer $offer,
        public Track $track,
        public ?Stage $from,
        public Stage $to,
        public ?Outcome $exit = null,
        public ?User $by = null,
        public ?\App\Offers\Deal $deal = null,
    ) {}
}
