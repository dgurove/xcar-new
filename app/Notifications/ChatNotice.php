<?php

namespace App\Notifications;

use App\Chats\Chat;
use App\Chats\Message;
use App\Push\WebPushChannel;
use App\Users\User;

/**
 * Сообщение в чате: пуш — на каждое (уведомления одного чата заменяют друг друга по tag), в ленту и
 * на почту — только первое непрочитанное. Адрес — экран чата в кабинете без хоста: сотруднику на
 * CRM он станет `/work/chats/{id}`, на сайте откроется там же. forStaff — адресат вторая сторона.
 */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff, private bool $first = true) {}

    public function via(User $user): array
    {
        $via = parent::via($user);

        return $this->first ? $via : array_values(array_intersect($via, [WebPushChannel::class]));
    }

    public function tag(): ?string
    {
        return 'chat-'.$this->message->chat_id;
    }

    public function title(): string
    {
        $chat = $this->message->chat;
        $who = $this->forStaff ? $chat->displayName() : ($chat->manager?->shortName() ?? Chat::PLATFORM);

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

    /**
     * Куда вести из уведомления и тоста: экран чата в кабинете — без хоста, чтобы установленное
     * приложение не уходило во встроенный браузер; на CRM `/account/chats/{id}` → `/work/chats/{id}`.
     */
    public static function hrefFor(Chat $chat, bool $forStaff): string
    {
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
