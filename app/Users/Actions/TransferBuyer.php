<?php

namespace App\Users\Actions;

use App\Notifications\BuyerJoinedNotice;
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

        DB::transaction(function () use ($buyer, $to) {
            Showing::where('user_id', $buyer->id)->delete();
            $buyer->groups()->detach();
            $buyer->forceFill(['manager_id' => $to->id, 'invite_id' => null])->save();
        });

        $to->notify(new BuyerJoinedNotice($buyer));

        return $buyer;
    }
}
