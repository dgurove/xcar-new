<?php

namespace App\Notifications;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Message;
use App\Push\WebPushChannel;
use App\Support\Surface;
use App\Telegram\Offers\OffersBotChannel;
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
        // Переписка начата в боте предложений — в Telegram отвечает он, а не основной бот (`OffersBotChannel`).
        if (OffersBotChannel::routes($this->message, $user)) {
            $via = [...array_diff($via, [TelegramChannel::class]), OffersBotChannel::class];
        }

        return $this->first ? array_values($via) : array_values(array_intersect($via, [WebPushChannel::class, TelegramChannel::class, OffersBotChannel::class]));
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function forStaff(): bool
    {
        return $this->forStaff;
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
        // Общий чат покупателя с его менеджером (без машины, 12.10.2026) — просто «пишет», это не обращение к площадке.
        $title = match (true) {
            $chat->isEnquiry() && $chat->isBuyerChat() => "{$who} пишет",
            $chat->isEnquiry() => "{$who}: обращение с сайта",
            default => "{$who} пишет по {$chat->offer->titleWithYear()}",
        };

        // Покупателю пишет его менеджер — без VIN и номера: VIN покупателю по умолчанию скрыт (`Offer::vinFor`).
        $toBuyer = $chat->isBuyerChat() && $m->author_id !== null && $m->author_id === $chat->manager_id;

        // VIN строкой — только открытый у предложения и не покупателю: скрытый ушёл бы в Telegram целиком (13.10.2026).
        $lines = $toBuyer || (! $this->forStaff && ! $chat->offer?->show_vin)
            ? array_values(array_filter([$m->preview(1000), $chat->offer?->number ? Text::tag($chat->offer) : null]))
            : Text::lines($chat->offer, $m->preview(1000));

        return ['title' => $title, 'lines' => $lines, 'button' => 'Открыть чат'];
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

    public function label(): string
    {
        return 'Сообщение в чате';
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

    /** Чат площадки сотруднику — работа CRM: только там (адрес у него сайтовый, `/account/chats/{id}`). */
    public function surface(): ?Surface
    {
        return $this->forStaff && ! $this->message->chat->isBuyerChat() ? Surface::Crm : parent::surface();
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

    /** Автор сообщения; автоответ площадки без автора — знак приложения. */
    public function actor(): ?User
    {
        return $this->message->author_id ? $this->message->author : null;
    }
}
