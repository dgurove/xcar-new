<?php

namespace App\Notifications;

use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Sender;
use App\Users\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Уведомление сотруднику письмом через SMTP ящика стоянки: системный мейлер на проде выключен (`MAIL_MAILER=log`),
 * а ящик уже настроен. Письмо не сохраняется в переписке — это не переписка, а копия уведомления.
 * Ошибка транспорта — в лог, без повторов: лента и пуш уже доставлены.
 */
final class MailboxChannel
{
    public static function account(): ?Account
    {
        $slug = config('xcar.notify_account');

        // Только ящик парковки: уведомление не уйдёт от offer@ или deal@, даже если их вписали в XCAR_NOTIFY_ACCOUNT.
        return ($slug ? Account::where('slug', $slug)->where('scope', Scope::Park)->where('is_active', true)->first() : null)
            ?? Account::where('scope', Scope::Park)->where('is_active', true)->orderBy('id')->first();
    }

    public function send(User $user, Notice $notice): Sent
    {
        $account = self::account();
        if (! $account || ! $user->email) {
            return Sent::skipped($account ? 'нет почты' : 'нет ящика парковки');
        }
        $mail = $notice->toMail($user);
        $email = (new Email)->from(new Address($account->email, $account->fromName()))->to(new Address($user->email, $user->name))
            ->subject((string) $mail->subject)->html($mail->render()->toHtml());
        try {
            Sender::transport($account)->send($email);
        } catch (Throwable $e) {
            Log::warning('notify mail: '.$e->getMessage(), ['user' => $user->id, 'title' => $notice->title()]);

            return Sent::failed($e->getMessage());
        }

        return Sent::ok();
    }
}
