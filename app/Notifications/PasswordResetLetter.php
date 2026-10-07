<?php

namespace App\Notifications;

use App\Mail\Account;
use App\Mail\Scope;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * «Забыл пароль» письмом через SMTP боевого ящика (`XCAR_SYSTEM_ACCOUNT`, по умолчанию deal@): системный мейлер
 * на проде выключен (`MAIL_MAILER=log`). Отправка сразу, в запросе: ссылка строится от адреса, на котором нажали.
 */
final class PasswordResetLetter extends ResetPassword
{
    public function via($notifiable): array
    {
        return [PasswordMailChannel::class];
    }

    public static function account(): ?Account
    {
        return Account::where('slug', config('xcar.system_account'))->where('is_active', true)->first()
            ?? Account::where('scope', Scope::Offers)->where('is_active', true)->orderBy('id')->first();
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)->subject('Новый пароль на xcar')->greeting($notifiable->name.',')
            ->line('Чтобы задать новый пароль, нажмите кнопку')
            ->action('Задать пароль', $this->resetUrl($notifiable))
            ->line("Ссылка работает {$minutes} минут. Если пароль не меняли, просто удалите письмо")
            ->salutation(config('app.name'));
    }
}
