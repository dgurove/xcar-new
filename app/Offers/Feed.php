<?php

namespace App\Offers;

use App\Telegram\Offers\OffersBot;
use App\Telegram\Offers\Subscriber;
use App\Users\User;
use App\WhatsApp\LinkCode;
use App\WhatsApp\Sender;

/**
 * Куда человеку приходят новые предложения (владелец 03.11.2026): Telegram-бот предложений — каждое лично, WhatsApp —
 * открытое всем в группе, открытое не всем и просьбы оценить — лично; можно оба. Основной бот Telegram — вход и всё, где
 * нужен человек, здесь его нет. Одна дверь для строки в «Предложениях», шторки «Куда присылать» и строк профиля.
 */
final class Feed
{
    private ?bool $subscribed = null;

    public function __construct(public readonly User $user) {}

    /** Бот предложений человеку служит. */
    public function telegram(): bool
    {
        return Subscriber::eligible($this->user) && app(OffersBot::class)->configured();
    }

    public function subscribed(): bool
    {
        return $this->subscribed ??= $this->telegram() && Subscriber::subscribed($this->user);
    }

    /** WhatsApp человеку предлагается: открыт менеджерам (`Sender::open`) и он из тех, кому служит. */
    public function whatsapp(): bool
    {
        return Sender::open() && LinkCode::eligible($this->user);
    }

    public function linked(): bool
    {
        return $this->user->whatsapp_jid !== null;
    }

    /** Есть что подключить. */
    public function any(): bool
    {
        return $this->telegram() || $this->whatsapp();
    }

    /** Строка в начале «Предложений»: ничего не подключено и не скрыта (× — на 30 дней). */
    public function banner(): bool
    {
        $hidden = $this->user->notification_settings['offers_bot_hidden'] ?? null;

        return $this->any() && ! $this->subscribed() && ! $this->linked() && (! $hidden || $hidden < now()->toDateString());
    }
}
