<?php

namespace App\Offers\Actions;

use App\Offers\Events\InterestRegistered;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

final class RegisterInterest
{
    public function __invoke(Offer $offer, User $by, ?string $comment = null): Interest
    {
        if (! $offer->state->acceptsInterest()) {
            throw ValidationException::withMessages(['interest' => 'Машина уже недоступна']);
        }

        $interest = $offer->interests()->firstOrNew(['user_id' => $by->id]);
        $fresh = ! $interest->exists;
        $interest->fill(['comment' => $comment ?: $interest->comment, 'state' => $fresh ? InterestState::New : $interest->state])->save();

        if ($fresh) {
            $offer->log(OfferEventType::Interest, $by, ['interest_id' => $interest->id]);
            InterestRegistered::dispatch($interest);
        }

        return $interest;
    }
}
