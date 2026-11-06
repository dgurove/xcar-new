<?php

namespace App\Notifications;

use App\Mail\SystemMail;
use App\Users\User;
use Illuminate\Support\Facades\Log;

/**
 * Канал письма «Забыл пароль»: системное письмо с no-reply (`SystemMail`, 06.11.2026; до того — SMTP ящика deal@). Сразу, в
 * запросе: ссылка строится от адреса, на котором нажали. Ошибка — в лог, экран отвечает как обычно. Подтверждённой почта
 * быть не обязана: письмо и есть способ войти.
 */
final class PasswordMailChannel
{
    public function send(User $user, PasswordResetLetter $notice): void
    {
        if (! $user->email) {
            Log::warning('password mail: нет адреса', ['user' => $user->id]);

            return;
        }
        $sent = SystemMail::sendNow($notice->letter($user));
        Log::info('password mail: '.$sent->status, ['user' => $user->id, 'detail' => $sent->detail]);
    }
}
