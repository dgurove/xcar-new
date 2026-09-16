<?php

namespace App\Notifications;

use App\Chats\Message;
use App\Support\Surface;
use Illuminate\Support\Str;

/** Первое непрочитанное в чате: участнику — на страницу предложения или в контакты, сотруднику — в чат CRM. */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff) {}

    public function title(): string
    {
        $chat = $this->message->chat;
        $who = $this->forStaff ? $chat->displayName() : 'XCar';

        return $chat->isEnquiry() ? "{$who}: обращение с сайта" : "{$who}: сообщение по № {$chat->offer->number}";
    }

    public function text(): ?string
    {
        return $this->message->text ? Str::limit($this->message->text, 120) : 'Файл';
    }

    public function href(): string
    {
        if ($this->forStaff) {
            return Surface::Crm->url("/work/chats/{$this->message->chat_id}");
        }

        return $this->message->chat->isEnquiry() ? '/contacts' : "/offers/{$this->message->chat->offer->number}?chat=1";
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
