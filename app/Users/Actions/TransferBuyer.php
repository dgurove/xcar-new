<?php

namespace App\Users\Actions;

use App\Notifications\BuyerJoinedNotice;
use App\Offers\Events\OffersHidden;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Покупатель переходит к другому менеджеру (прежний ушёл). Показы и группы
 * прежнего снимаются явно — видимость сверяет менеджера показа с менеджером
 * покупателя, и без этого у человека молча пропало бы всё.
 */
final class TransferBuyer
{
    public function __invoke(User $buyer, User $to): User
    {
        if (! $buyer->isBuyer() || ! $to->isManager() || $buyer->manager_id === $to->id) {
            return $buyer;
        }

        // Что человек видел до передачи — эти карточки должны уйти из его открытой ленты.
        $seen = Offer::where('state', OfferState::Open)->visibleTo($buyer)->pluck('number')->all();
        DB::transaction(function () use ($buyer, $to) {
            Showing::where('user_id', $buyer->id)->delete();
            $buyer->groups()->detach();
            $buyer->forceFill(['manager_id' => $to->id, 'invite_id' => null])->save();
        });
        if ($seen) {
            OffersHidden::dispatch($to, [$buyer->id => $seen]);
        }

        $to->notify(new BuyerJoinedNotice($buyer));

        return $buyer;
    }
}
