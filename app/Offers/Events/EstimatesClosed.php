<?php

namespace App\Offers\Events;

use App\Offers\Offer;
use Illuminate\Foundation\Events\Dispatchable;

/** Просьбы оценить закрыты (цена поставлена, черновик ушёл, админ снял): «Нужно оценить» у этих менеджеров пустеет. */
final class EstimatesClosed
{
    use Dispatchable;

    /** @param  list<int>  $estimateIds  @param  list<int>  $userIds */
    public function __construct(public Offer $offer, public array $estimateIds, public array $userIds) {}
}
