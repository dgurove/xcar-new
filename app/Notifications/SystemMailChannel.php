<?php

namespace App\Notifications;

use App\Mail\SystemMail;
use App\Users\User;

/**
 * Уведомление письмом с no-reply (`SystemMail`): что слать — решает `Notice::via` (`User::wantsMailFor`). Итог — в журнал
 * доставки; не принял сервер — повтор (`RetryDelivery`).
 */
final class SystemMailChannel
{
    public function send(User $user, Notice $notice): Sent
    {
        if (! $user->email) {
            return Sent::skipped('нет почты');
        }

        $sent = SystemMail::sendNow($notice->toSystemMail($user));
        // Почтовый сервер не принял — повтор заданием через 1, 5, 15 минут (`RetryDelivery`, 19.11.2026).
        if ($sent->status === 'failed' && $notice->id) {
            throw RetryDelivery::after($notice, $user->id, 'mail', (string) $sent->detail);
        }

        return $sent->status === 'sent' ? Sent::ok(retries: RetryDelivery::retries()) : $sent;
    }
}
