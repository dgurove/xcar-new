<?php

namespace App\Offers\Events;

use App\Offers\Bid;
use Illuminate\Foundation\Events\Dispatchable;

/** Менеджер ответил на контрпредложение: согласился (сделка уже открыта) или отказался. */
final class CounterAnswered
{
    use Dispatchable;

    public function __construct(public Bid $bid, public bool $agreed) {}
}
