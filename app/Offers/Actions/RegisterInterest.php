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
    /** `$placedBy` — сотрудник, что вносит интерес за менеджера (тот сказал устно); уведомлений тогда нет. */
    public function __invoke(Offer $offer, User $by, ?string $comment = null, ?User $placedBy = null): Interest
    {
        if (! $offer->acceptsInterest()) {
            throw ValidationException::withMessages(['interest' => 'ТС уже недоступно']);
        }

        $interest = $offer->interests()->firstOrNew(['user_id' => $by->id]);
        $fresh = ! $interest->exists;
        $interest->fill(['comment' => $comment ?: $interest->comment, 'state' => $fresh ? InterestState::New : $interest->state])
            ->forceFill($fresh && $placedBy ? ['placed_by' => $placedBy->id] : [])->save();

        if ($fresh) {
            $offer->log(OfferEventType::Interest, $by, ['interest_id' => $interest->id] + ($placedBy ? ['placed_by' => $placedBy->id] : []));
            InterestRegistered::dispatch($interest);
        }

        return $interest;
    }
}
