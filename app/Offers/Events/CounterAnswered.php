<?php

namespace App\Offers\Events;

use App\Offers\Bid;
use Illuminate\Foundation\Events\Dispatchable;

/** Менеджер ответил на контрпредложение: согласился (сделка уже открыта) или отказался. */
final class CounterAnswered
{
    use Dispatchable;

    /** stuck — согласился резерв при идущей сделке, а передать сразу не вышло (`Give` не пустил): почему — админам передать руками. */
    public function __construct(public Bid $bid, public bool $agreed, public ?\App\Users\User $by = null, public ?string $stuck = null) {}
}
