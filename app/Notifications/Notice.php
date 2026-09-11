<?php

namespace App\Notifications;

use App\Users\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Уведомление: заголовок, текст, куда ведёт. В ленту кабинета всегда,
 * на почту — если у человека есть адрес и он её не выключил. Пуш — этап 8.
 */
abstract class Notice extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function title(): string;

    public function text(): ?string
    {
        return null;
    }

    abstract public function href(): string;

    public function offerNumber(): ?int
    {
        return null;
    }

    public function via(User $user): array
    {
        return $user->email && $user->wantsMail() ? ['database', 'mail'] : ['database'];
    }

    public function toArray(User $user): array
    {
        return ['title' => $this->title(), 'text' => $this->text(), 'href' => $this->href(), 'offer' => $this->offerNumber()];
    }

    public function toMail(User $user): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting($user->name.',')->line($this->title());
        if ($this->text()) {
            $mail->line($this->text());
        }

        return $mail->action('Открыть', url($this->href()))->salutation(config('app.name'));
    }
}
