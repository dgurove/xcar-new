<?php

namespace App\Notifications;

use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Sender;
use App\Users\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * «Забыл пароль» письмом через SMTP боевого ящика (`XCAR_SYSTEM_ACCOUNT`, по умолчанию deal@): системный мейлер
 * на проде выключен (`MAIL_MAILER=log`). Отправка сразу, в запросе: ссылка строится от адреса, на котором нажали.
 */
final class PasswordResetLetter extends ResetPassword
{
    public function via($notifiable): array
    {
        return [self::class];
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

    public function send(User $user, self $notice): void
    {
        $account = self::account();
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
