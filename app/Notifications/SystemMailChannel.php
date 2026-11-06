<?php

namespace App\Notifications;

use App\Mail\SystemMail;
use App\Users\User;

/** Уведомление письмом с no-reply (`SystemMail`): что слать — решает `Notice::via` (`User::wantsMailFor`). Итог — в журнал доставки. */
final class SystemMailChannel
{
    public function send(User $user, Notice $notice): Sent
    {
        if (! $user->email) {
            return Sent::skipped('нет почты');
        }

        return SystemMail::sendNow($notice->toSystemMail($user));
    }
}
