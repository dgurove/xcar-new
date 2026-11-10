<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру: укажите покупателя (10.11.2026, `Offers\Actions\AskBuyer`) — сразу после «Беру в работу», чтобы реквизиты ушли
 * страховой в день запроса. Ссылка — на сделку с окном «Кто покупатель?» (`?buyer=1`).
 */
final class BuyerAskNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    /** Покупателя указали раньше, чем дошла очередь, — не шлём. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Deal::whereKey($this->deal->id)->whereNotNull('buyer_asked_at')->exists() && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return 'Укажите покупателя';
    }

    public function title(): string
    {
        return 'Укажите покупателя — № '.$this->deal->offer->number;
    }

    public function text(): ?string
    {
        return 'Страховая попросит реквизиты, отправим сразу';
    }

    public function href(): string
    {
        return $this->deal->href().'?buyer=1';
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Укажите покупателя: '.$this->deal->offer->titleWithYear(),
            'lines' => Text::lines($this->deal->offer, 'Страховая попросит реквизиты, отправим сразу'),
            'button' => 'Указать покупателя'];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
