<?php

namespace App\Purchases\Actions;

use App\Purchases\Offer;
use App\Purchases\OfferState;
use App\Users\User;
use Illuminate\Validation\ValidationException;

final class WithdrawOffer
{
    public function __invoke(Offer $offer, User $by): void
    {
        if ($offer->user_id !== $by->id || $offer->state !== OfferState::Active) {
            throw ValidationException::withMessages(['offer' => 'Отозвать нечего']);
        }
        $offer->update(['state' => OfferState::Withdrawn]);
    }
}
