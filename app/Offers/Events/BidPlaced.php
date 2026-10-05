<?php

namespace App\Offers\Events;

use App\Offers\Bid;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class BidPlaced
{
    use Dispatchable;

    /** @param  bool  $byStaff  внёс админ за менеджера — сотрудникам «новое подтверждение» не шлём */
    public function __construct(public Bid $bid, public ?User $by = null, public bool $byStaff = false) {}
}
