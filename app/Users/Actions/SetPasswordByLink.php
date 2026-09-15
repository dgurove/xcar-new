<?php

namespace App\Users\Actions;

use App\Users\PasswordLink;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Человек открыл ссылку и придумал пароль: пароль меняется, ссылка сгорает, чужие сессии сбрасываются. */
final class SetPasswordByLink
{
    public function __invoke(PasswordLink $link, string $password): User
    {
        return DB::transaction(function () use ($link, $password) {
            $user = $link->user;
            $user->forceFill(['password' => $password])->save();
            $link->forceFill(['used_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            return $user;
        });
    }
}
