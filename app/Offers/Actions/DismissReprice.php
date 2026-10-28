<?php

namespace App\Offers\Actions;

use App\Offers\OfferEventType;
use App\Offers\Reprice;
use App\Users\User;

/** «Отклонить» переоценку (28.10.2026): закупочная и деньги не тронуты, плашка уходит, в истории — что отклонили. */
final class DismissReprice
{
    public function __invoke(Reprice $reprice, User $by): void
    {
        if (! $reprice->isPending()) {
            return;
        }
        $reprice->update(['state' => Reprice::DISMISSED, 'decided_by' => $by->id, 'decided_at' => now()]);
        $reprice->offer->log(OfferEventType::Reprice, $by, ['reprice' => $reprice->id, 'state' => Reprice::DISMISSED,
            'from' => $reprice->from_price, 'to' => $reprice->to_price]);
    }
}
