<?php

namespace App\Users\Actions;

use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Разъединить связанные аккаунты (`LinkAccounts`): оба снова сами по себе. */
final class UnlinkAccounts
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->linked_user_id)->where('linked_user_id', $user->id)->update(['linked_user_id' => null]);
            $user->forceFill(['linked_user_id' => null])->save();
        });
    }
}
