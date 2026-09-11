<?php

namespace App\Notifications;

use App\Chats\Message;

/** Первое непрочитанное в чате: участнику — на страницу оффера, сотруднику — в чат. */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff) {}

    public function title(): string
    {
        $who = $this->forStaff ? ($this->message->chat->user->name) : 'XCar';

        return "{$who}: сообщение по № {$this->message->chat->offer->number}";
    }

    public function text(): ?string
    {
        return $this->message->text ? \Illuminate\Support\Str::limit($this->message->text, 120) : 'Файл';
    }

    public function href(): string
    {
        return $this->forStaff ? "/admin/chaty/{$this->message->chat_id}" : "/offers/{$this->message->chat->offer->number}?chat=1";
    }

    public function offerNumber(): ?int
    {
        return $this->message->chat->offer->number;
    }
}
