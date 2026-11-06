<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/** «Забыл пароль» письмом с no-reply (`PasswordMailChannel`, `SystemMail`): ссылка строится от адреса, на котором нажали. */
final class PasswordResetLetter extends ResetPassword
{
    public function via($notifiable): array
    {
        return [PasswordMailChannel::class];
    }

    public function letter($notifiable): \Symfony\Component\Mime\Email
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $html = view('emails.password', [
            'title' => 'Новый пароль', 'subtitle' => $notifiable->name, 'preheader' => 'Ссылка, чтобы задать новый пароль',
            'button' => ['text' => 'Задать пароль', 'url' => $this->resetUrl($notifiable)],
            'note' => "Ссылка работает {$minutes} минут. Если пароль не меняли, просто удалите письмо",
        ])->render();

        return \App\Mail\SystemMail::make($notifiable->email, $notifiable->name, 'Новый пароль на xcar', $html);
    }
}
