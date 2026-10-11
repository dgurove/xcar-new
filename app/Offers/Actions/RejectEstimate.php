<?php

namespace App\Offers\Actions;

use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimateRejected;
use App\Offers\OfferEventType;
use App\Support\Nav;
use App\Users\User;

/** «Отклонить» (владелец 11.10.2026: без причины, срок прежний): оценка стирается, менеджер вводит новую. */
final class RejectEstimate
{
    public function __invoke(Estimate $estimate, User $by): Estimate
    {
        if (! $estimate->isGiven()) {
            return $estimate;
        }
        $amount = $estimate->amount;
        $estimate->update(['state' => EstimateState::Asked, 'rejected_amount' => $amount, 'rejected_at' => now(), 'amount' => null, 'estimated_at' => null]);
        $estimate->offer->log(OfferEventType::Estimate, $by, ['do' => 'rejected', 'user' => $estimate->user_id, 'amount' => $amount]);
        Nav::forgetStaffCounts();
        EstimateRejected::dispatch($estimate, $by);

        return $estimate;
    }
}
