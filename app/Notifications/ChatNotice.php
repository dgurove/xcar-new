<?php

namespace App\Notifications;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Message;
use App\Push\WebPushChannel;
use App\Support\Surface;
use App\Telegram\Text;
use App\Users\User;

/**
 * Сообщение в чате: пуш и Telegram — на каждое (пуши одного чата заменяют друг друга по tag, в Telegram — каждое со
 * звуком, решение владельца 01.10.2026), в ленту и на почту — только первое непрочитанное, лента держит строку на чат.
 * Адрес — экран чата в кабинете без хоста: сотруднику на CRM он станет `/work/chats/{id}`, на сайте откроется там же.
 * forStaff — адресат вторая сторона.
 */
final class ChatNotice extends Notice
{
    public function __construct(private Message $message, private bool $forStaff, private bool $first = true) {}

    public function via(User $user): array
    {
        $via = parent::via($user);

        return $this->first ? $via : array_values(array_intersect($via, [WebPushChannel::class, TelegramChannel::class]));
    }

    public function subject(): string
    {
        return '/account/chats/'.$this->message->chat_id;
    }

    /** В Telegram — сообщение целиком; автоответ площадки (без автора) — нет: человек сам только что написал. */
    public function toTelegram(): ?array
    {
        $m = $this->message;
        if ($m->author_kind !== AuthorKind::Participant && ! $m->author_id) {
            return null;
        }
        $chat = $m->chat;
        $who = $this->who();
        $title = $chat->isEnquiry() ? "{$who}: обращение с сайта" : "{$who} пишет по {$chat->offer->titleWithYear()}";

        return ['title' => $title, 'lines' => Text::lines($chat->offer, $m->preview(1000)), 'button' => 'Открыть чат'];
    }

    /** Сотруднику в чате площадки — CRM (там и обращения с сайта), остальным — сайт. */
    public function telegramUrl(): string
    {
        $chat = $this->message->chat;

        return $this->forStaff && ! $chat->isBuyerChat() ? Surface::Crm->url('/work/chats/'.$chat->id) : parent::telegramUrl();
    }

    private function who(): string
    {
        $chat = $this->message->chat;

        return $this->forStaff ? $chat->displayName() : ($chat->manager?->shortName() ?? Chat::PLATFORM);
    }

    public function title(): string
    {
        $chat = $this->message->chat;
        $who = $this->who();

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
