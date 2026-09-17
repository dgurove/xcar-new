<?php

namespace App\Notifications;

use App\Chats\Chat;
use App\Chats\Message;
use App\Users\User;
use App\Support\Surface;

/**
 * Сообщение в чате: пуш — на каждое (уведомления одного чата заменяют друг друга по tag), в ленту и
 * на почту — только первое непрочитанное. Участнику — на его экран чата, сотруднику — в CRM,
 * менеджеру покупателя — в кабинет. forStaff — адресат вторая сторона.
 */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff, private bool $first = true) {}

    public function via(User $user): array
    {
        $via = parent::via($user);

        return $this->first ? $via : array_values(array_intersect($via, [\App\Push\WebPushChannel::class]));
    }

    public function tag(): ?string
    {
        return 'chat-'.$this->message->chat_id;
    }

    public function title(): string
    {
        $chat = $this->message->chat;
        $who = $this->forStaff ? $chat->displayName() : ($chat->manager?->shortName() ?? 'XCar');

        return $chat->isEnquiry() ? "{$who}: обращение с сайта" : "{$who}: сообщение по № {$chat->offer->number}";
    }

    public function text(): ?string
    {
        return $this->message->preview(120);
    }

    public function href(): string
    {
        return self::hrefFor($this->message->chat, $this->forStaff);
    }

    /** Куда вести из уведомления и тоста: сотруднику площадки — CRM, всем остальным — экран чата в кабинете. */
    public static function hrefFor(Chat $chat, bool $forStaff): string
    {
        if ($forStaff && ! $chat->manager_id) {
            return Surface::Crm->url("/work/chats/{$chat->id}");
        }
        if (! $chat->user_id) {
            return '/contacts';
        }

        return "/account/chats/{$chat->id}";
    }

    public function offerNumber(): ?int
    {
        return $this->message->chat->offer?->number;
    }

    public function category(): string
    {
        return 'chats';
    }
}
