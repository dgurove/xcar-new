<?php

namespace App\Notifications;

use App\Mail\Sender;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Канал письма «Забыл пароль»: SMTP боевого ящика, ошибка — в лог, экран отвечает как обычно. */
final class PasswordMailChannel
{
    public function send(User $user, PasswordResetLetter $notice): void
    {
        $account = PasswordResetLetter::account();
        if (! $account || ! $user->email) {
            Log::warning('password mail: нет ящика или адреса', ['user' => $user->id]);

            return;
        }
        $mail = $notice->toMail($user);
        $email = (new Email)->from(new Address($account->email, $account->fromName()))->to(new Address($user->email, $user->name))
            ->subject((string) $mail->subject)->html($mail->render()->toHtml());
        try {
            Sender::transport($account)->send($email);
            Log::info('password mail: ушло', ['user' => $user->id, 'from' => $account->email]);
        } catch (Throwable $e) {
            Log::warning('password mail: '.$e->getMessage(), ['user' => $user->id]);
        }
    }
}
