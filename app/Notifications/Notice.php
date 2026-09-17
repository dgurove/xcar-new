<?php

namespace App\Notifications;

use App\Users\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Уведомление: заголовок, текст, куда ведёт. Куда доставлять — решают настройки
 * человека (User::notification_settings): категории, почта, тихие часы.
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

    /** Метка пуша: уведомления с одной меткой заменяют друг друга; по умолчанию — предложение. */
    public function tag(): ?string
    {
        return $this->offerNumber() ? 'offer-'.$this->offerNumber() : null;
    }

    /** Категория для настроек (Categories): что человек может выключить. */
    public function category(): string
    {
        return 'other';
    }

    /** Критичное приходит всегда: ответ на подтверждение, сделки, выбор цены. */
    public function critical(): bool
    {
        return false;
    }

    /**
     * Куда: выключенная категория — никуда; лента всегда; пуш — не в тихие часы;
     * почта — если есть адрес и не выключена.
     */
    public function via(User $user): array
    {
        if (! $this->critical() && ! $user->wants($this->category())) {
            return [];
        }
        $via = ['database'];
        if (! $user->quietHours()) {
            $via[] = \App\Push\WebPushChannel::class;
        }
        if ($user->email && $user->wantsMail()) {
            $via[] = 'mail';
        }

        return $via;
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

        return $mail->action('Открыть', url($this->href()))->salutation(config('app.name'))
            ->line(new \Illuminate\Support\HtmlString('<a href="'.e($user->unsubscribeUrl()).'" style="color:#808080">Не присылать на почту</a>'));
    }
}
